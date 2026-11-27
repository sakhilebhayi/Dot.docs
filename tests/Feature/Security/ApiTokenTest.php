<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentStore;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-1 / S-07 (owner decision 2026-11-27: keep the API, make it safe). Real tokens, sent as a bearer header: the
 * page guard and the use log only act on a stored PersonalAccessToken, which Sanctum::actingAs() does not create.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user, array $abilities): string
    {
        return $user->createToken('integration', $abilities)->plainTextToken;
    }

    private function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    public function test_a_read_token_lists_its_company_documents_and_nothing_from_another_company(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $mine = $store->create($user, 'Board pack');
        $store->create($stranger, 'Someone else');

        $this->api($this->token($user, ['read']))->getJson('/api/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $mine->uuid);
    }

    public function test_show_gives_markdown_and_refuses_a_document_the_owner_cannot_open(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $mine = $store->create($user, 'Minutes', ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Agreed.']]]]]);
        $theirs = $store->create($stranger, 'Private');
        $token = $this->token($user, ['read']);

        $this->api($token)->getJson("/api/documents/{$mine->uuid}")->assertOk()
            ->assertJsonPath('title', 'Minutes')
            ->assertJson(fn ($json) => $json->where('markdown', fn ($md) => str_contains($md, 'Agreed.'))->etc());
        // Another company's document: 404, so the API does not confirm it exists.
        $this->api($token)->getJson("/api/documents/{$theirs->uuid}")->assertNotFound();
    }

    public function test_each_route_needs_its_own_permission(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Draft');
        $read = $this->token($user, ['read']);

        $this->api($read)->postJson('/api/documents', ['title' => 'New', 'markdown' => 'x'])->assertForbidden();
        $this->api($read)->putJson("/api/documents/{$doc->uuid}", ['markdown' => 'x', 'base_version' => 1])->assertForbidden();
        $this->api($this->token($user, ['create']))->getJson('/api/documents')->assertForbidden();

        $this->assertSame(1, Document::count());
    }

    public function test_create_and_update_write_through_the_store_and_a_stale_base_changes_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $created = $this->api($this->token($user, ['create']))
            ->postJson('/api/documents', ['title' => 'From the API', 'markdown' => "# Plan\n\nFirst line."])
            ->assertCreated()->json();
        $doc = Document::where('uuid', $created['uuid'])->firstOrFail();
        $this->assertSame($user->id, $doc->owner_id);
        $this->assertSame($user->currentTeam->id, $doc->team_id);
        $this->assertStringContainsString('First line.', (string) $doc->search_text);

        $update = $this->token($user, ['update']);
        $this->api($update)->putJson("/api/documents/{$doc->uuid}", ['markdown' => 'Second.', 'base_version' => $doc->version + 5])
            ->assertStatus(409)->assertJsonPath('version', $doc->version);
        $this->assertStringContainsString('First line.', (string) $doc->fresh()->search_text);

        $this->api($update)->putJson("/api/documents/{$doc->uuid}", ['markdown' => 'Second.', 'base_version' => $doc->version])
            ->assertOk()->assertJsonPath('version', $doc->version + 1);
        $this->assertStringContainsString('Second.', (string) $doc->fresh()->search_text);
    }

    public function test_a_token_never_opens_the_sites_pages(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');
        $token = $this->token($user, ['read', 'create', 'update', 'delete']);

        foreach (['/documents', "/documents/{$doc->uuid}/edit", "/documents/{$doc->uuid}/export/markdown", '/user/profile', '/user/api-tokens'] as $page) {
            $this->api($token)->get($page)->assertForbidden();
        }

        // A browser session is not affected.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($user)->get('/documents')->assertOk();
    }

    public function test_every_call_is_logged_with_the_token_route_and_answer(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $this->token($user, ['read']);

        $this->api($token)->getJson('/api/user')->assertOk()->assertExactJson(['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
        $this->api($token)->postJson('/api/documents', ['title' => 'x', 'markdown' => ''])->assertForbidden();

        $rows = AuditLog::where('action', 'api.request')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['token' => 'integration', 'method' => 'GET', 'route' => 'api.user', 'status' => 200], $rows[0]->context);
        $this->assertSame(403, $rows[1]->context['status']);
        $this->assertSame($user->id, $rows[1]->actor_id);
    }

    public function test_a_token_stops_working_after_ninety_days(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $this->token($user, ['read']);

        $this->travel(89)->days();
        $this->api($token)->getJson('/api/user')->assertOk();

        $this->travel(2)->days();
        $this->api($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_session_does_not_reach_the_api(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user, 'web')->getJson('/api/documents')->assertUnauthorized();
    }

    /** The review's exploit: a Livewire action posted with a bearer token. Refused before any component runs. */
    public function test_a_token_cannot_drive_a_livewire_action(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $this->token($user, ['read']);

        $this->api($token)->postJson('/livewire/update', ['components' => []])->assertForbidden();
    }

    public function test_another_bearer_credential_on_a_web_route_is_not_mistaken_for_an_api_token(): void
    {
        config(['guardian.token' => 'guardian-secret-for-test']);

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Authorization' => 'Bearer guardian-secret-for-test'])->get('/guardian/health');

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_show_and_update_stay_inside_the_company_the_owner_is_standing_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $elsewhere = app(DocumentStore::class)->create($user, 'Old company doc');
        $other = Team::forceCreate(['user_id' => $user->id, 'name' => 'Second company', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $other->id])->save();

        $token = $this->token($user->fresh(), ['read', 'update']);
        $this->api($token)->getJson("/api/documents/{$elsewhere->uuid}")->assertNotFound();
        $this->api($token)->putJson("/api/documents/{$elsewhere->uuid}", ['markdown' => 'x', 'base_version' => 1])->assertNotFound();
    }
}
