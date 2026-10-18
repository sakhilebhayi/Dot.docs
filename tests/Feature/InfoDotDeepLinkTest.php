<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use App\Observers\InfoDotResourceObserver;
use App\Services\InfoDot\ResourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deep links from InfoDot: documents are registered as pointers only, /o/{uuid} opens a
 * document for people its policy allows (and nobody else), and a delete drops the pointer.
 */
class InfoDotDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private User $gina;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot.secret' => 'docs-secret',
            'ecosystem.infodot.redeem_url' => 'https://infodot.test/api/ecosystem/redeem',
            'ecosystem.infodot.platform' => 'docs',
        ]);

        $this->ann = User::factory()->withPersonalTeam()->create();
        $this->gina = User::factory()->withPersonalTeam()->create();
        $this->acme = $this->company($this->ann, 'Acme', (string) Str::uuid());
        $this->globex = $this->company($this->gina, 'Globex', (string) Str::uuid());
    }

    private function company(User $owner, string $name, ?string $uuid): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        $owner->switchTeam($team);

        return $team;
    }

    private function doc(User $owner, Team $team, string $title = 'Board minutes'): Document
    {
        return Document::factory()->create(['owner_id' => $owner->id, 'team_id' => $team->id, 'title' => $title]);
    }

    private function fakeInfoDot(int $status = 201): void
    {
        $this->swap(HttpFactory::class, new HttpFactory);
        Http::fake(['infodot.test/*' => Http::response(['uuid' => (string) Str::uuid(), 'reference' => 'ACME-D-000001', 'url' => 'https://infodot.test/open/x'], $status)]);
    }

    public function test_registering_sends_a_pointer_only_and_keeps_the_reference(): void
    {
        $this->fakeInfoDot();
        $document = $this->doc($this->ann, $this->acme, 'Confidential merger plan');

        $this->assertTrue(app(ResourceRegistry::class)->register($document));

        Http::assertSent(function ($request) use ($document) {
            $body = $request->data();

            return $request->url() === 'https://infodot.test/api/ecosystem/resources'
                && $request->hasHeader('Authorization', 'Bearer docs-secret')
                && $body['platform'] === 'docs'
                && $body['type'] === 'document'
                && $body['team_uuid'] === $this->acme->infodot_team_uuid
                && $body['object_uuid'] === $document->uuid
                && $body['path'] === '/o/'.$document->uuid
                && ! str_contains(json_encode($body), 'merger') && ! array_key_exists('title', $body);
        });
        $this->assertSame('ACME-D-000001', Document::find($document->id)->infodot_reference);
    }

    public function test_personal_and_unlinked_teams_are_never_registered(): void
    {
        $this->fakeInfoDot();
        $unlinked = $this->company($this->gina, 'Initech', null);

        foreach ([$this->ann->personalTeam(), $unlinked] as $team) {
            $this->assertFalse(app(ResourceRegistry::class)->register($this->doc($this->ann, $team)));
        }

        Http::assertNothingSent();
    }

    public function test_an_unreachable_infodot_never_breaks_saving(): void
    {
        $this->fakeInfoDot(500);
        $document = $this->doc($this->ann, $this->acme);

        $this->assertFalse(app(ResourceRegistry::class)->register($document));
        $this->assertNull(Document::find($document->id)->infodot_reference);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_a_new_document_in_a_linked_company_is_registered_after_the_response(): void
    {
        $this->fakeInfoDot();

        $document = $this->doc($this->ann, $this->acme);
        app()->terminate();

        Http::assertSentCount(1);
        $this->assertSame('ACME-D-000001', Document::find($document->id)->infodot_reference);
    }

    public function test_autosaves_do_not_chatter_but_a_move_between_companies_does(): void
    {
        $recorder = new class extends InfoDotResourceObserver
        {
            public array $calls = [];

            protected function register(Document $document): void
            {
                $this->calls[] = 'register';
            }

            protected function remove(Document $document): void
            {
                $this->calls[] = 'remove';
            }
        };

        $document = $this->doc($this->ann, $this->acme);

        $document->update(['title' => 'Renamed']);
        $recorder->updated($document);
        $this->assertSame([], $recorder->calls, 'a title change sends nothing');

        $document->update(['team_id' => $this->globex->id]);
        $recorder->updated($document);
        $this->assertSame(['register'], $recorder->calls, 'a move between companies re-registers');
    }

    public function test_trashing_a_document_drops_its_pointer(): void
    {
        $this->fakeInfoDot();
        $document = $this->doc($this->ann, $this->acme);
        app()->terminate();
        $uuid = $document->uuid;

        Document::find($document->id)->delete();
        app()->terminate();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/ecosystem/resources/'.$uuid));
    }

    public function test_a_member_opens_the_document_and_the_company_becomes_current(): void
    {
        $document = $this->doc($this->ann, $this->acme);
        $this->ann->switchTeam($this->ann->personalTeam());

        $this->actingAs($this->ann)->get('/o/'.$document->uuid)->assertRedirect(route('documents.edit', $document->uuid));

        $this->assertSame($this->acme->id, $this->ann->fresh()->current_team_id);
    }

    public function test_another_company_gets_request_access_and_learns_nothing(): void
    {
        $document = $this->doc($this->ann, $this->acme, 'Secret merger memo');

        $this->actingAs($this->gina)->get('/o/'.$document->uuid)
            ->assertForbidden()
            ->assertSee('not in the company that owns this document')
            ->assertDontSee('Secret merger memo')
            ->assertDontSee('Acme');

        $this->assertSame($this->globex->id, $this->gina->fresh()->current_team_id);
        $this->actingAs($this->gina)->get('/o/'.(string) Str::uuid())->assertNotFound();
        $this->actingAs($this->gina)->get('/o/not-a-uuid')->assertNotFound();
    }

    public function test_signed_out_people_are_sent_to_login(): void
    {
        $this->get('/o/'.$this->doc($this->ann, $this->acme)->uuid)->assertRedirect('/login');
    }

    public function test_the_backfill_registers_only_linked_company_documents(): void
    {
        $this->fakeInfoDot();
        $mine = $this->doc($this->ann, $this->acme);
        $this->doc($this->ann, $this->ann->personalTeam());
        Http::fake();
        $this->fakeInfoDot();

        $this->artisan('documents:register-resources')->assertSuccessful();

        $this->assertNotNull(Document::find($mine->id)->infodot_reference);
    }
}
