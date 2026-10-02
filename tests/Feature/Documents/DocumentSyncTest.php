<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The endpoint an open editor polls: what version is the document at, who
 * else is here, and - only when the caller is behind - the document itself.
 */
class DocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    private function doc(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Synced');
    }

    public function test_a_caller_that_is_up_to_date_gets_the_version_and_who_is_here_but_no_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $doc = $this->doc($user);

        // `protocol` is what the engine sends with every poll; it is accepted.
        $response = $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a', 'protocol' => 1])
            ->assertOk()
            ->assertJson(['version' => 1, 'changed' => false, 'others' => 0]);

        $this->assertSame(['Thandi'], array_column($response->json('members'), 'name'));
        $this->assertArrayNotHasKey('json', $response->json());
        $this->assertArrayNotHasKey('outline', $response->json());
        $this->assertArrayNotHasKey('css', $response->json());
    }

    public function test_a_caller_that_is_behind_gets_the_document_its_outline_and_its_stylesheet(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        app(DocumentStore::class)->save($doc, $this->para('Newer text'), $user);

        $response = $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])
            ->assertOk()
            ->assertJson(['version' => 2, 'changed' => true]);

        $this->assertSame('doc', $response->json('json.type'));
        $this->assertSame('Newer text', $response->json('json.content.0.content.0.text'));
        $this->assertSame(
            ['numbers', 'toc', 'figures', 'tables', 'pageSetup', 'headerSegments', 'footerSegments'],
            array_keys($response->json('outline')),
        );

        // The canvas stylesheet of the document's style, exactly as the
        // editor page prints it: somebody else may have changed the style.
        $styles = app(StyleEngine::class);
        $this->assertNotSame('', $response->json('css'));
        $this->assertSame($styles->css($styles->resolve($doc->fresh()), 'canvas'), $response->json('css'));
    }

    /**
     * This runs every 1.5 seconds per open tab. When nothing has changed it
     * must never read the document's content out of the database.
     */
    public function test_a_quiet_poll_never_loads_the_document_content(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])
            ->assertOk();

        $documentQueries = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, '"documents"')));

        $this->assertNotEmpty($documentQueries);
        foreach ($documentQueries as $sql) {
            $this->assertStringNotContainsString('*', $sql, "A quiet poll selected every column: {$sql}");
            $this->assertStringNotContainsString('content', $sql, "A quiet poll read document content: {$sql}");
        }
    }

    public function test_each_poll_records_the_tab_and_reports_the_others(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $guest = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $guest->id, 'role' => 'editor']);

        $this->actingAs($owner)->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-owner'])
            ->assertJson(['others' => 0]);

        $response = $this->actingAs($guest)->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-guest'])
            ->assertJson(['others' => 1]);

        $this->assertCount(2, $response->json('members'));
    }

    public function test_a_leaving_tab_is_removed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])->assertOk();
        $this->assertSame(1, DB::table('document_presences')->count());

        $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a', 'leaving' => true])
            ->assertOk()
            ->assertExactJson(['left' => true]);

        $this->assertSame(0, DB::table('document_presences')->count());
    }

    /** Access is checked on every poll, so removing someone cuts off an open page. */
    public function test_someone_who_may_not_view_the_document_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);

        $this->actingAs($outsider)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-x'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('document_presences')->count());
    }

    public function test_a_guest_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);

        $this->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-x'])
            ->assertUnauthorized();
    }

    public function test_an_unknown_document_is_not_found(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->postJson(route('documents.sync', 'no-such-uuid'), ['version' => 1, 'tab' => 'tab-a'])
            ->assertNotFound();
    }

    public function test_a_malformed_body_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 'latest', 'tab' => 'has spaces and <tags>', 'protocol' => 'one'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['version', 'tab', 'protocol']);
    }
}
