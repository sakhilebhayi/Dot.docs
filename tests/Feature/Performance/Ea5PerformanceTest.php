<?php

namespace Tests\Feature\Performance;

use App\Documents\DocumentStore;
use App\Jobs\CoalescedSaveWebhooks;
use App\Jobs\DeliverDocumentWebhooks;
use App\Livewire\Documents\Editor;
use App\Models\DocumentWebhook;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/** EA-5 (enterprise audit P-01..P-13): the save path does less, and says so. */
class Ea5PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    public function test_a_steady_save_sends_no_page_back_and_the_document_is_not_in_the_snapshot(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');
        $page = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        $this->assertArrayNotHasKey('contentJson', $page->snapshot['data']);

        $page->call('saveContent', $this->para('one'), 1);   // first save after load renders
        $this->assertArrayHasKey('html', $page->effects);

        $page->call('saveContent', $this->para('two'), 2);   // every save after that does not
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertSame(3, $doc->fresh()->version);
    }

    public function test_the_first_good_save_after_a_refused_one_renders_to_clear_the_error(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');
        $page = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        $page->call('saveContent', $this->para('ok'), 1);
        $page->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]], 2)->assertHasErrors('content');
        $page->call('saveContent', $this->para('fine again'), 2)->assertHasNoErrors('content');
        $this->assertArrayHasKey('html', $page->effects);
    }

    public function test_a_save_queues_no_webhook_job_when_the_document_has_no_active_webhook(): void
    {
        Queue::fake();
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        app(DocumentStore::class)->save($doc, $this->para('x'), $user);

        Queue::assertNotPushed(DeliverDocumentWebhooks::class);
        Queue::assertNotPushed(CoalescedSaveWebhooks::class);
    }

    public function test_a_save_queues_one_delayed_delivery_and_an_export_queues_one_now(): void
    {
        Queue::fake();
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');
        DocumentWebhook::create(['document_id' => $doc->id, 'user_id' => $user->id, 'url' => 'https://93.184.216.34/hook', 'events' => ['on_save', 'on_export'], 'status' => 'active']);

        app(DocumentStore::class)->save($doc, $this->para('x'), $user);
        Queue::assertPushed(CoalescedSaveWebhooks::class, fn ($job) => $job->event === 'on_save' && $job->delay !== null);

        $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'markdown']))->assertOk();
        Queue::assertPushed(DeliverDocumentWebhooks::class, fn ($job) => $job->event === 'on_export' && $job->delay === null && ! $job instanceof CoalescedSaveWebhooks);
    }

    public function test_the_hot_lookups_have_indexes(): void
    {
        foreach ([['document_versions', 'ea5_document_versions_document_id_version_number_index'], ['document_webhooks', 'ea5_document_webhooks_document_id_index'], ['team_user', 'ea5_team_user_user_id_index'], ['comments', 'ea5_comments_document_id_created_at_index']] as [$table, $index]) {
            $this->assertTrue(Schema::hasIndex($table, $index), "{$table}: {$index}");
        }
    }

    public function test_the_version_label_follows_the_page_not_the_last_render(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertSee('data-doc-version wire:ignore', false);
        $this->assertStringContainsString('paintVersion(view.baseVersion);', implode("\n", array_map('file_get_contents', [resource_path('js/editor/sync/host.js'), ...glob(resource_path('js/editor/sync/host/*.js'))])));
    }

    public function test_a_save_sharing_its_request_with_another_call_still_renders(): void
    {
        $part = fn (array $calls, array $updates = []) => [[
            'snapshot' => json_encode(['memo' => ['id' => 'ed1']]),
            'updates' => $updates,
            'calls' => $calls,
        ]];

        $this->assertTrue(Editor::soleCallIn($part([['method' => 'saveContent']]), 'ed1'));
        $this->assertFalse(Editor::soleCallIn($part([['method' => 'toggleCommentSidebar'], ['method' => 'saveContent']]), 'ed1'), 'a bundled sidebar toggle must be drawn');
        $this->assertFalse(Editor::soleCallIn($part([['method' => 'saveContent']], ['title' => 'New']), 'ed1'), 'a property change with it must be drawn');
        $this->assertFalse(Editor::soleCallIn($part([['method' => 'saveContent']]), 'other'), 'unknown component: render');
    }

    public function test_a_save_queued_during_a_delivery_is_not_dropped(): void
    {
        $this->assertContains(ShouldBeUniqueUntilProcessing::class, class_implements(CoalescedSaveWebhooks::class));
        $this->assertNotContains(ShouldBeUnique::class, class_implements(DeliverDocumentWebhooks::class));
    }
}
