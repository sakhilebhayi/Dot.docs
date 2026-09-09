<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The beacon autosave endpoint.
 *
 * The 1200 ms autosave debounce is flushed on `pagehide`, and at that point a
 * Livewire request cannot be issued at all — Livewire's CommitBus defers every
 * call on a 5 ms timer that the unloading page never runs. `navigator.sendBeacon`
 * is the only send the browser guarantees to deliver, and it can only POST to a
 * plain endpoint, which is this one.
 */
class DocumentAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private function doc(User $user, string $title = 'Beacon')
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, $title);
    }

    private function docJson(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    public function test_a_beacon_post_stores_the_document_and_returns_the_new_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $before = $doc->version;

        $response = $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->docJson('Last words')]);

        $response->assertOk()->assertJson(['version' => $before + 1]);

        $doc->refresh();
        $this->assertSame('Last words', $doc->search_text);
        $this->assertSame('Last words', $doc->content_json['content'][0]['content'][0]['text']);
    }

    public function test_a_beacon_post_keeps_the_documents_doc_attrs(): void
    {
        // $request->validate() returns ONLY the keys it was given rules for,
        // so validating `content.type`/`content.content` and then saving the
        // validated array silently dropped `content.attrs` - and ensureIds()
        // re-stamped schema/style/vars defaults on every navigation away.
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $content = $this->docJson('Attrs survive');
        $content['attrs'] = ['schema' => 1, 'style' => 'legal', 'vars' => ['client' => 'Acme']];

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $content])
            ->assertOk();

        $doc->refresh();
        $this->assertSame(
            ['schema' => 1, 'style' => 'legal', 'vars' => ['client' => 'Acme']],
            $doc->content_json['attrs']
        );
    }

    public function test_the_beacon_save_cuts_no_version_snapshot(): void
    {
        // ['version' => 'none']: the writer navigating away is not a moment
        // worth a named point in the document's history.
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->docJson('Flushed')])
            ->assertOk();

        $this->assertSame(0, $doc->versions()->count());
    }

    public function test_invalid_document_json_is_rejected_with_422(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $stored = $doc->content_json;

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => ['type' => 'doc', 'content' => [
                ['type' => 'mermaidDiagram', 'attrs' => ['id' => BlockId::generate()]],
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertSame($stored, $doc->fresh()->content_json);
    }

    public function test_a_body_that_is_not_a_document_is_rejected_with_422(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => ['type' => 'paragraph']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content.type');

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');
    }

    public function test_another_user_cannot_autosave_the_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);
        $stranger = User::factory()->withPersonalTeam()->create();
        $stored = $doc->content_json;

        $this->actingAs($stranger)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->docJson('Not mine')])
            ->assertForbidden();

        $this->assertSame($stored, $doc->fresh()->content_json);
    }

    public function test_a_guest_cannot_autosave_the_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);

        $this->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->docJson('Anon')])
            ->assertUnauthorized();
    }

    public function test_the_csrf_token_is_read_from_an_application_json_body(): void
    {
        // sendBeacon cannot set headers, so there is no X-CSRF-TOKEN to send;
        // the token rides in the JSON body instead. Laravel's CSRF middleware
        // reads it with `$request->input('_token')` (PreventRequestForgery::
        // getTokenFromRequest()), and for an application/json request `input()`
        // reads the decoded JSON — asserted directly here because the
        // middleware short-circuits itself under `runningUnitTests()`, so an
        // HTTP-level test could not prove it.
        $request = Request::create(
            route('documents.autosave', 'any-uuid'),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['_token' => 'beacon-token', 'content' => $this->docJson('Body')])
        );

        $this->assertSame('beacon-token', $request->input('_token'));
        $this->assertSame('doc', $request->input('content.type'));
    }
}
