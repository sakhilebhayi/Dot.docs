<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Http\Middleware\StartSession;
use App\Livewire\HeaderSearch;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-9 / R2-9: the editor's sync poll does not write the session row back when it changed nothing in it, so a poll
 * no longer takes SQLite's write lock for its session - and signing in, staying signed in and the session's expiry
 * all still work. Runs on the database session driver (production's), signed in through a real session row and
 * cookie rather than actingAs(), and forgets the in-memory session before every request as a new PHP process would.
 */
class SyncQuietSessionTest extends TestCase
{
    use RefreshDatabase;

    private int $sessionWrites = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();

        DB::listen(function ($query): void {
            if (preg_match('/^\s*(insert into|update)\s+"sessions"/i', $query->sql) === 1) {
                $this->sessionWrites++;
            }
        });
    }

    /** A stored session signed in as $user, as the login page would leave it. Returns its id. */
    private function signIn(User $user): string
    {
        $this->forgetSession();
        $id = Str::random(40);
        $store = $this->app['session']->driver();
        $store->setId($id);
        $store->start();
        $store->put(Auth::guard('web')->getName(), $user->getKey());
        $store->save();
        $this->forgetSession();
        $this->sessionWrites = 0;

        return $id;
    }

    /** What a fresh PHP process starts with: no session in memory, no signed-in user. */
    private function forgetSession(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->defaultCookies = [];
    }

    private function poll(string $sessionId, Document $doc, int $version = 1): TestResponse
    {
        $this->forgetSession();

        return $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => $version, 'tab' => 'tab-a']);
    }

    private function document(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Quiet');
    }

    public function test_polls_that_change_nothing_in_the_session_do_not_write_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->document($user);
        $id = $this->signIn($user);

        // The first poll stores what it must (AuthenticateSession's password hash, the quiet-write time): one write.
        $this->poll($id, $doc)->assertOk();
        $this->assertSame(1, $this->sessionWrites);
        $lastActivity = DB::table('sessions')->where('id', $id)->value('last_activity');

        $this->sessionWrites = 0;
        $this->travel(10)->seconds();
        foreach (range(1, 10) as $_) {
            $this->poll($id, $doc)->assertOk()->assertJson(['version' => 1, 'changed' => false]);
        }

        $this->assertSame(0, $this->sessionWrites, 'Ten quiet polls wrote the session row.');
        $this->assertSame($lastActivity, DB::table('sessions')->where('id', $id)->value('last_activity'));
    }

    public function test_a_quiet_poll_still_refreshes_the_session_every_few_minutes_so_it_does_not_expire(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->document($user);
        $id = $this->signIn($user);
        $this->poll($id, $doc)->assertOk();
        $before = (int) DB::table('sessions')->where('id', $id)->value('last_activity');

        $this->sessionWrites = 0;
        $this->travel(StartSession::REFRESH_SECONDS + 1)->seconds();
        $this->poll($id, $doc)->assertOk();

        $this->assertSame(1, $this->sessionWrites);
        $this->assertGreaterThan($before, (int) DB::table('sessions')->where('id', $id)->value('last_activity'));

        // And quiet again straight after.
        $this->poll($id, $doc)->assertOk();
        $this->assertSame(1, $this->sessionWrites);
    }

    public function test_the_person_stays_signed_in_across_quiet_polls_and_a_stranger_is_still_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->document($user);
        $id = $this->signIn($user);

        foreach (range(1, 5) as $_) {
            $this->poll($id, $doc)->assertOk();
            $this->assertTrue(Auth::guard('web')->check());
            $this->assertSame($user->getKey(), Auth::guard('web')->id());
        }

        // The stored row still signs the person in (nothing was lost by skipping the writes).
        $payload = json_decode((string) base64_decode((string) DB::table('sessions')->where('id', $id)->value('payload')), true);
        $this->assertSame($user->getKey(), $payload[Auth::guard('web')->getName()]);

        // No session: refused, as before.
        $this->forgetSession();
        $this->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])->assertUnauthorized();

        // An unknown or expired session id: refused, and the editor sees the 401 it handles.
        $this->poll(Str::random(40), $doc)->assertUnauthorized();

        // Somebody signed in who may not read the document: refused on every poll.
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->poll($this->signIn($stranger), $doc)->assertForbidden();
    }

    public function test_a_change_to_the_session_during_a_poll_is_still_saved(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->document($user);
        $id = $this->signIn($user);
        $this->poll($id, $doc)->assertOk();

        // Somebody else saves: the poll that brings the newer document changes nothing in the session either.
        app(DocumentStore::class)->save($doc->fresh(), ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Newer']]],
        ]], $user);
        $this->sessionWrites = 0;
        $this->poll($id, $doc)->assertOk()->assertJson(['changed' => true]);
        $this->assertSame(0, $this->sessionWrites);

        // Flash data waiting to be aged is a change: the poll writes, as before.
        $this->forgetSession();
        $store = $this->app['session']->driver();
        $store->setId($id);
        $store->start();
        $store->flash('status', 'Saved');
        $store->save();
        $this->sessionWrites = 0;
        $this->poll($id, $doc, 2)->assertOk();
        $this->assertSame(1, $this->sessionWrites);
    }

    public function test_other_routes_still_write_the_session_on_every_request(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->document($user);
        $id = $this->signIn($user);
        $this->poll($id, $doc)->assertOk();

        $this->sessionWrites = 0;
        foreach (range(1, 2) as $_) {
            $this->forgetSession();
            $this->withCredentials()->withCookie(config('session.cookie'), $id)
                ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['editor_open' => 1]])
                ->assertNoContent();
        }

        $this->assertSame(2, $this->sessionWrites);
    }

    public function test_livewire_updates_that_change_nothing_in_the_session_do_not_write_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->document($user);
        $id = $this->signIn($user);
        $this->poll($id, $doc)->assertOk();

        // A real component snapshot, as the page holds it, and an update that calls nothing.
        $snapshot = json_encode(Livewire::actingAs($user)->test(HeaderSearch::class)->snapshot);
        $this->forgetSession();

        $this->sessionWrites = 0;
        $this->travel(10)->seconds();
        foreach (range(1, 3) as $_) {
            $this->forgetSession();
            $this->withCredentials()->withCookie(config('session.cookie'), $id)
                ->withHeader('X-Livewire', '')
                ->postJson(route('default.livewire.update'), ['components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => []]]])
                ->assertOk();
        }

        $this->assertSame(0, $this->sessionWrites, 'Three unchanged Livewire updates wrote the session row.');
    }
}
