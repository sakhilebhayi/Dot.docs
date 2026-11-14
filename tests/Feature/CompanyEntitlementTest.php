<?php

namespace Tests\Feature;

use App\Documents\DocumentStore;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.docs honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the current company still holds the platform. A clear
 * "no" is the plain 403 page; every failure lets the person carry on. Response bodies follow InfoDot's own tests
 * (CompanyPlatformsEndpointTest, the redeem controller).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'docs', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);

        // The shell's "Dot Apps" switcher (dot-ui) reads the same endpoint under its own cache key. These tests count
        // and sequence the ENTITLEMENT client's reads, so the switcher's answer is parked here (a cached failure, which
        // makes it show its degraded state without asking). The switcher itself is covered by DotUiHeaderTest.
        foreach ([self::ACME, self::GLOBEX] as $uuid) {
            Cache::forever('infodot:platforms:docs:'.$uuid, ['ok' => false, 'personal' => false, 'rows' => []]);
        }
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's CompanyPlatformsEndpointTest asserts it. */
    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'docs',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'docs', 'name' => 'Dot.docs', 'status' => $status, 'entitled' => $entitled, 'is_core' => true, 'access_mode' => 'everyone'],
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function write(User $author, Team $team, string $title): Document
    {
        return app(DocumentStore::class)->create($author, $title, [
            'type' => 'doc',
            'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quarterly plan body.']]]],
        ], ['team_id' => $team->id]);
    }

    /** @return array{0: User, 1: Team, 2: Document} an owner, their InfoDot company, and a document in it */
    private function company(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        $owner ??= User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $document = $this->write($owner->fresh(), $team, 'Quarterly plan '.$name);

        return [$owner->fresh(), $team, $document];
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'docs',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('auth.ecosystem'), ['token' => 'one-time'], $this->fromInfodot());
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        [$globexOwner, $globex] = $this->company(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first(), 'a company that does not hold the platform is not created');
        $this->assertSame('Globex (old name)', $globex->fresh()->name, 'nor renamed');
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role, 'nor is the role changed');
        $this->assertTrue($ann->belongsToTeam($globex), 'membership is left as it was');
        $this->assertSame($acme->id, $ann->current_team_id, 'and it cannot be the current company');
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam('44444444-4444-4444-8444-444444444444', "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.docs needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/docs"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is created for nothing');
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('documents.index'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $request['platform'] === 'docs'
            && $request->hasHeader('X-Dot-Platform', 'docs')
            && $request->hasHeader('Authorization', 'Bearer '.$this->credential));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.docs is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.docs is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.docs needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.docs has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_and_no_data(string $status, string $title): void
    {
        [$owner, $team, $document] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        foreach ([route('dashboard'), route('documents.index'), route('documents.edit', $document->uuid), route('documents.open', $document->uuid), route('documents.share', $document->uuid), route('files.index'), route('documents.export', [$document->uuid, 'html'])] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertStatus(403)
                ->assertSee($title)
                ->assertSee('href="https://infodot.test/platforms/docs"', false)
                ->assertDontSee('Quarterly plan')
                ->assertDontSee($document->uuid);

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        // Action requests are refused too, and nothing changes.
        $this->actingAs($owner)->postJson(route('documents.images.store', $document->uuid))->assertStatus(403);
        $this->assertSame(1, Document::query()->count());
        $this->assertSame('Quarterly plan Acme Ltd', $document->fresh()->title, 'the data is untouched');
    }

    public static function failures(): array
    {
        return [
            'bad secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'throttled (429)' => [fn () => Http::response('', 429)],
            'server error (500)' => [fn () => Http::response('boom', 500)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'docs', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'docs', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'entitled is not a boolean' => [fn () => Http::response(['platform' => 'docs', 'platforms' => [['key' => 'docs', 'status' => 'suspended', 'entitled' => 'false']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'docs', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner] = $this->company();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        $logged = [];
        Log::partialMock()->shouldReceive('warning')->andReturnUsing(function ($message) use (&$logged) {
            $logged[] = $message;
        });

        $this->actingAs($owner)->get(route('documents.index'))->assertOk()->assertSee('Quarterly plan Acme Ltd');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        $this->assertCount(1, array_filter($logged, fn ($m) => str_contains((string) $m, 'InfoDot entitlement check gave no answer')), 'logged once per cache window');
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('documents.index'))->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->actingAs($owner)->get(route('documents.index'))->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->actingAs($owner)->get(route('documents.index'))->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->actingAs($owner)->get(route('documents.index'))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('documents.index'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('documents.index'))->assertOk();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('documents.index'))->assertOk();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('DOCS_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_a_personal_workspace_or_a_company_infodot_does_not_know_is_not_asked_about(): void
    {
        $this->infodotSays([]);

        $solo = User::factory()->withPersonalTeam()->create();
        $this->actingAs($solo)->get(route('dashboard'))->assertOk();

        $local = Team::forceCreate(['user_id' => $solo->id, 'name' => 'Local Co', 'personal_team' => false]);
        $solo->forceFill(['current_team_id' => $local->id])->save();
        $this->actingAs($solo->fresh())->get(route('documents.index'))->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ personal workspaces (individuals hold the free core only)

    private const PERSONAL = '44444444-4444-4444-8444-444444444444';

    /** Ann signs in with one entitled company and a personal workspace marked as given, then works in her local personal team. */
    private function signInThenWorkPersonally(?bool $personalEntitled): array
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect(route('dashboard'));

        // The test client does not carry the session between requests; hand over what sign-in remembered.
        $this->withSession(session()->has(EnsureCompanyEntitled::PERSONAL) ? [EnsureCompanyEntitled::PERSONAL => session(EnsureCompanyEntitled::PERSONAL)] : []);

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $personal = $ann->personalTeam();
        $document = $this->write($ann, $personal, 'Private stock count');
        $ann->forceFill(['current_team_id' => $personal->id])->save();

        $this->actingAs($ann->fresh());

        return [$ann->fresh(), $personal, $document, Team::where('infodot_team_uuid', self::ACME)->firstOrFail()];
    }

    public function test_a_personal_workspace_infodot_marked_not_entitled_is_refused_without_asking_the_read_endpoint(): void
    {
        [$ann, $personal, $document, $acme] = $this->signInThenWorkPersonally(false);
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        foreach ([route('dashboard'), route('documents.index'), route('documents.edit', $document->uuid)] as $url) {
            $response = $this->get($url)->assertStatus(403)
                ->assertSee('Dot.docs needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/docs"', false)
                ->assertSee('name="team_id" value="'.$acme->id.'"', false)
                ->assertDontSee('Private stock count');

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        Http::assertNothingSent();

        // Nothing in the workspace is deleted or altered.
        $this->assertSame('Private stock count', $document->fresh()->title);
        $this->get(route('profile.show'))->assertOk();

        // The entitled company can still be switched to.
        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_team_with_no_infodot_company_is_refused_the_same_way(): void
    {
        [$ann] = $this->signInThenWorkPersonally(false);
        $local = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Local Co', 'personal_team' => false]);
        $ann->forceFill(['current_team_id' => $local->id])->save();
        $this->infodotSays([]);

        $this->get(route('documents.index'))->assertStatus(403)->assertSee('Dot.docs needs a company that has switched it on');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        [$ann, , $document] = $this->signInThenWorkPersonally(true);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('documents.edit', $document->uuid))->assertOk()->assertSee('Private stock count');
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_workspace_carries_on(): void
    {
        [, , $document] = $this->signInThenWorkPersonally(null);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('documents.edit', $document->uuid))->assertOk()->assertSee('Private stock count');
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInThenWorkPersonally(false);
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', personal: true), $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect();

        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        $this->signInThenWorkPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get(route('documents.index'))->assertOk();
    }

    /** A real Livewire update request (not the test harness, which skips persistent middleware) for the documents index. */
    private function livewireSnapshot(): string
    {
        $page = $this->get(route('documents.index'))->assertOk()->getContent();
        $snapshot = '';
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $all);
        foreach ($all[1] as $encoded) {
            $decoded = html_entity_decode($encoded, ENT_QUOTES);
            if (($data = json_decode($decoded, true)) && ($data['memo']['name'] ?? null) === 'documents') {
                $snapshot = $decoded;
            }
        }
        $this->assertNotSame('', $snapshot, 'the documents index component is on the page');

        return $snapshot;
    }

    private function livewireCreateDocument(string $snapshot)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['newDocumentName' => 'Made through Livewire'],
            'calls' => [['path' => '', 'method' => 'createDocumentAtLocation', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner, $team] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'active'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);
        $before = Document::query()->where('team_id', $team->id)->count();
        $snapshot = $this->livewireSnapshot();

        // While the company holds the platform the action works.
        $worked = $this->livewireCreateDocument($snapshot);
        $this->assertNotSame(403, $worked->getStatusCode());
        $this->assertSame($before + 1, Document::query()->where('team_id', $team->id)->count());

        // The cached answer expires, InfoDot now says no: the same action is refused and returns no component.
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $this->livewireCreateDocument($snapshot);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('components', (array) $response->json());
        $this->assertSame($before + 1, Document::query()->where('team_id', $team->id)->count(), 'the data is unchanged');
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$owner, , $document] = $this->company();
        $this->infodotSays([]);

        // Signed out: public pages, health, sign-in, the handoff receiver's legacy GET, a document that is not shared.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');

        // Signed in, in a company that would be refused if anyone asked: public pages, the person's own account,
        // the machine paths of co-editing, and signing out.
        $this->actingAs($owner);
        $this->get('/')->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get('/up')->assertOk();
        $this->postJson(route('documents.sync', $document->uuid), [])->assertStatus(422);
        $this->postJson(route('documents.sync-metrics', $document->uuid), [])->assertStatus(422);
        $this->postJson(route('documents.autosave', $document->uuid), [])->assertStatus(422);
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_published_and_shared_pages_stay_open_when_the_company_no_longer_holds_the_platform(): void
    {
        [$owner, , $document] = $this->company();
        $document->forceFill(['is_public' => true])->save();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        $this->actingAs($owner)->get(route('dashboard'))->assertStatus(403);
        $this->get(route('documents.shared', $document->uuid))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $this->get(route('documents.shared', $document->uuid))->assertOk();
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->company();
        [, $globex] = $this->company(self::GLOBEX, 'Globex Corporation', $owner);
        $owner->forceFill(['current_team_id' => $acme->id])->save();

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'active')),
        ]);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Globex Corporation')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
        $this->get(route('documents.index'))->assertOk();
    }

    public function test_livewire_updates_from_the_signed_in_area_carry_the_check(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }
}
