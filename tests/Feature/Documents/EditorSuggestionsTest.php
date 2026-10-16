<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Livewire\Documents\Editor;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditorSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_a_suggestion_writes_through_document_store(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');

        $suggestion = AiSuggestion::create([
            'document_id' => $doc->id,
            'user_id' => $user->id,
            'suggestion_text' => '<h1>Suggested Heading</h1><p>Suggested body</p>',
            'created_at' => now(),
        ]);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('acceptSuggestion', $suggestion->id)
            ->assertSet('saved', true);

        $doc->refresh();
        $this->assertSame('heading', $doc->content_json['content'][0]['type']);
        $this->assertNotNull($doc->fresh());

        $latestVersion = $doc->versions()->latest('id')->first();
        $this->assertSame('named', $latestVersion->kind);
        $this->assertSame('Accepted suggestion', $latestVersion->label);

        $this->assertNotNull($suggestion->fresh()->accepted_at);
    }

    /**
     * An accepted suggestion replaces the whole document. What it replaces
     * may be in no version row - an autosave cuts none while the same
     * author's last one is under two minutes old - and every other open tab
     * follows the replacement, so the replaced text must be kept first.
     */
    public function test_accepting_a_suggestion_keeps_the_document_it_replaces(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Report');

        $para = fn (string $text): array => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];

        // Two autosaves by one author inside two minutes: only the first cuts a version.
        $store->save($doc, $para('First autosave'), $user);
        $store->save(Document::findOrFail($doc->id), $para('Second autosave, in no version'), $user);
        $this->assertSame([2], DocumentVersion::where('document_id', $doc->id)->pluck('version_number')->all());

        $suggestion = AiSuggestion::create([
            'document_id' => $doc->id,
            'user_id' => $user->id,
            'suggestion_text' => '<p>Suggested body</p>',
            'created_at' => now(),
        ]);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('acceptSuggestion', $suggestion->id);

        $this->assertSame('Suggested body', $doc->fresh()->search_text);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('label', 'Before accepted suggestion')->sole();

        $this->assertSame('named', $kept->kind);
        $this->assertSame(3, $kept->version_number);
        $this->assertSame('Second autosave, in no version', $kept->content_json['content'][0]['content'][0]['text']);
    }
}
