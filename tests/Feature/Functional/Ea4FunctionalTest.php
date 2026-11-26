<?php

namespace Tests\Feature\Functional;

use App\Documents\DocumentStore;
use App\Livewire\Documents\AiAssistant;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\VersionHistory;
use App\Livewire\Documents\WebhookManager;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\DocumentWebhook;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** EA-4 (enterprise audit F-01..F-12, U-01): controls do what they say. */
class Ea4FunctionalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Document} */
    private function ownerAndDoc(): array
    {
        $user = User::factory()->withPersonalTeam()->create();

        return [$user, app(DocumentStore::class)->create($user, 'Notes')];
    }

    public function test_suggesting_mode_is_hidden_until_it_works(): void
    {
        [$user, $doc] = $this->ownerAndDoc();

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertDontSee('Suggest instead of editing');
        $this->assertFalse(method_exists(Editor::class, 'toggleSuggestionMode'));
    }

    public function test_a_rewrite_replaces_the_document_and_continue_inserts_at_the_cursor(): void
    {
        [$user, $doc] = $this->ownerAndDoc();

        Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'grammar')
            ->assertSet('applyMode', 'replace')
            ->assertSee('Replace the document with it')
            ->call('applyResult')
            ->assertDispatched('ai-apply', mode: 'replace');

        Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'continue')
            ->assertSet('applyMode', 'insert')
            ->assertSee('Insert it at the cursor')
            ->call('applyResult')
            ->assertDispatched('ai-apply', mode: 'insert');

        Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc])
            ->set('command', '/continue')->call('runCommand')
            ->assertSet('applyMode', 'insert');
    }

    public function test_a_refusal_or_an_error_can_never_be_accepted_into_the_document(): void
    {
        [$user, $doc] = $this->ownerAndDoc();

        $this->partialMock(AiService::class, function ($mock) {
            $mock->shouldReceive('checkRateLimit')->andReturn(false);
        });
        Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'summarize')
            ->assertSet('applyMode', null)
            ->assertDontSee('Insert it at the cursor')
            ->call('applyResult')
            ->assertNotDispatched('ai-apply');
    }

    public function test_a_failed_call_offers_nothing_to_accept(): void
    {
        [$user, $doc] = $this->ownerAndDoc();
        $this->partialMock(AiService::class, function ($mock) {
            $mock->shouldReceive('checkRateLimit')->andReturn(true);
            $mock->shouldReceive('summarize')->andThrow(new RuntimeException('down'));
        });

        Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'summarize')
            ->assertSet('applyMode', null)
            ->call('applyResult')
            ->assertNotDispatched('ai-apply');
    }

    public function test_the_mock_assistant_says_it_is_a_demonstration_and_stores_no_suggestion(): void
    {
        [$user, $doc] = $this->ownerAndDoc();

        Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'summarize')
            ->assertSee('Demonstration answer');

        $this->assertSame(0, AiSuggestion::count());
    }

    public function test_a_status_message_after_a_redirect_reaches_the_editor(): void
    {
        [$user, $doc] = $this->ownerAndDoc();

        $this->actingAs($user)
            ->withSession(['status' => 'Saved to Dot.Files.'])
            ->get(route('documents.edit', $doc->uuid))
            ->assertSee('Saved to Dot.Files.');
    }

    public function test_a_new_webhook_shows_its_full_secret_once_and_errors_are_shown(): void
    {
        [$user, $doc] = $this->ownerAndDoc();

        $page = Livewire::actingAs($user)->test(WebhookManager::class, ['document' => $doc])
            ->set('newUrl', 'https://93.184.216.34/hook')
            ->call('addWebhook');

        $secret = DocumentWebhook::firstOrFail()->secret;
        $this->assertSame(32, strlen($secret));
        $page->assertSee($secret)->call('forgetSecret')->assertDontSee($secret);

        $hook = DocumentWebhook::firstOrFail();
        $page->call('rejectWebhook', $hook->id, '   ')
            ->assertHasErrors('webhook')
            ->assertSee('A rejection reason is required.');
    }

    public function test_compare_puts_the_older_version_left_and_keeps_paragraphs_apart(): void
    {
        $this->assertSame("Title\nFirst\nSecond", VersionHistory::blocksAsLines('<h1>Title</h1><p>First</p><p>Second</p>'));

        [$user, $doc] = $this->ownerAndDoc();
        $v1 = DocumentVersion::create(['document_id' => $doc->id, 'version_number' => 50, 'created_by' => $user->id, 'content_snapshot' => '<p>Old line</p>']);
        $v2 = DocumentVersion::create(['document_id' => $doc->id, 'version_number' => 51, 'created_by' => $user->id, 'content_snapshot' => '<p>New line</p>']);

        $html = Livewire::actingAs($user)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->set('compareIds', [$v2->id, $v1->id])
            ->call('runDiff')
            ->get('diffHtml');

        $this->assertLessThan(strpos($html, 'New'), strpos($html, 'Old'), 'older text on the left');
    }
}
