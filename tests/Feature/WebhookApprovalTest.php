<?php

namespace Tests\Feature;

use App\Livewire\Documents\WebhookManager;
use App\Models\Document;
use App\Models\DocumentWebhook;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WebhookApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $owner): Document
    {
        return Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Test document',
            'owner_id' => $owner->id,
        ]);
    }

    public function test_fire_does_not_deliver_to_a_pending_approval_webhook(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'pending_approval',
        ]);

        (new WebhookService)->fire($document, 'on_save');

        Http::assertNothingSent();
    }

    public function test_fire_does_not_deliver_to_a_rejected_webhook(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'rejected',
            'rejected_reason' => 'Not needed.',
        ]);

        (new WebhookService)->fire($document, 'on_save');

        Http::assertNothingSent();
    }

    public function test_fire_delivers_to_an_active_webhook(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            // An IP LITERAL, not a hostname: WebhookService::fire() now runs
            // App\Support\SsrfGuard over the URL, and the guard resolves a
            // hostname through DNS, which an offline test run cannot do.
            // filter_var() validates a literal directly. See WebhookSsrfTest.
            'url' => 'https://93.184.216.34/hook',
            'events' => ['on_save'],
            'status' => 'active',
        ]);

        (new WebhookService)->fire($document, 'on_save');

        Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/hook');
    }

    public function test_add_webhook_creates_a_pending_approval_webhook(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner);

        Livewire::actingAs($owner)
            ->test(WebhookManager::class, ['document' => $document])
            ->set('newUrl', 'https://example.com/hook')
            ->call('addWebhook');

        $webhook = DocumentWebhook::first();
        $this->assertSame('pending_approval', $webhook->status);
    }

    public function test_toggle_webhook_is_a_no_op_on_a_pending_approval_webhook(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $webhook = DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'pending_approval',
        ]);

        Livewire::actingAs($owner)
            ->test(WebhookManager::class, ['document' => $document])
            ->call('toggleWebhook', $webhook->id);

        $this->assertSame('pending_approval', $webhook->fresh()->status);
    }

    public function test_approve_webhook_flips_pending_to_active(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $webhook = DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'pending_approval',
        ]);

        Livewire::actingAs($owner)
            ->test(WebhookManager::class, ['document' => $document])
            ->call('approveWebhook', $webhook->id);

        $fresh = $webhook->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($owner->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_reject_webhook_without_a_reason_is_blocked(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $webhook = DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'pending_approval',
        ]);

        Livewire::actingAs($owner)
            ->test(WebhookManager::class, ['document' => $document])
            ->set('rejectReason', '')
            ->call('rejectWebhook', $webhook->id, '');

        $this->assertSame('pending_approval', $webhook->fresh()->status);
    }

    public function test_reject_webhook_with_a_reason_marks_it_rejected(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $webhook = DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'pending_approval',
        ]);

        Livewire::actingAs($owner)
            ->test(WebhookManager::class, ['document' => $document])
            ->call('rejectWebhook', $webhook->id, 'Not needed.');

        $fresh = $webhook->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Not needed.', $fresh->rejected_reason);
        $this->assertSame($owner->id, $fresh->reviewed_by);
    }

    public function test_non_owner_non_admin_cannot_approve(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $webhook = DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => 'https://example.com/hook',
            'events' => ['on_save'],
            'status' => 'pending_approval',
        ]);
        $stranger = User::factory()->create();

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::actingAs($stranger)
            ->test(WebhookManager::class, ['document' => $document])
            ->call('approveWebhook', $webhook->id);
    }
}
