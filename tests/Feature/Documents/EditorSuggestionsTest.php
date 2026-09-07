<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\AiSuggestion;
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
}
