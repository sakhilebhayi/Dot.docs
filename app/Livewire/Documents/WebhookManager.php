<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WebhookManager extends Component
{
    use AuthorizesRequests;

    public Document $document;

    // New webhook form
    public string $newUrl = '';

    /** @var array<mixed> */
    public array $newEvents = ['on_save', 'on_export'];

    public bool $generateSecret = true;

    /**
     * The new endpoint's full signing secret, shown ONCE right after it is
     * created so the receiver can verify X-Dotdocs-Signature; the list only
     * ever shows its first characters (EA-4 / F-07). Locked: display only.
     */
    #[Locked]
    public ?string $revealedSecret = null;

    // Reject confirmation state -- one webhook at a time, mirroring the
    // confirm-then-act pattern already used for Dot.Mines' Level 2 process.
    public ?int $rejectingWebhookId = null;

    public string $rejectReason = '';

    public function mount(Document $document): void
    {
        $this->document = $document;
    }

    public function addWebhook(): void
    {
        $this->authorize('manage', $this->document);

        $this->validate([
            'newUrl' => 'required|url|max:500',
        ]);

        $secret = $this->generateSecret ? Str::random(32) : null;

        DocumentWebhook::create([
            'document_id' => $this->document->id,
            'user_id' => auth()->id(),
            'url' => $this->newUrl,
            'events' => $this->newEvents ?: ['on_save', 'on_export'],
            'secret' => $secret,
            'status' => 'pending_approval',
        ]);

        $this->revealedSecret = $secret;

        $this->newUrl = '';
        $this->newEvents = ['on_save', 'on_export'];
        $this->generateSecret = true;
    }

    public function toggleWebhook(int $id): void
    {
        $this->authorize('manage', $this->document);

        $webhook = DocumentWebhook::where('document_id', $this->document->id)->findOrFail($id);

        if (! in_array($webhook->status, ['active', 'inactive'], true)) {
            $this->addError('webhook', 'This webhook must be approved before it can be toggled.');

            return;
        }

        $webhook->update(['status' => $webhook->status === 'active' ? 'inactive' : 'active']);
    }

    public function deleteWebhook(int $id): void
    {
        $this->authorize('manage', $this->document);

        DocumentWebhook::where('document_id', $this->document->id)->findOrFail($id)->delete();
    }

    public function approveWebhook(int $id): void
    {
        $this->authorize('manage', $this->document);

        $webhook = DocumentWebhook::where('document_id', $this->document->id)->findOrFail($id);

        if ($webhook->status !== 'pending_approval') {
            $this->addError('webhook', 'Only a pending webhook can be approved.');

            return;
        }

        $webhook->update([
            'status' => 'active',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejectWebhook(int $id, string $reason): void
    {
        $this->authorize('manage', $this->document);

        $webhook = DocumentWebhook::where('document_id', $this->document->id)->findOrFail($id);

        if ($webhook->status !== 'pending_approval') {
            $this->addError('webhook', 'Only a pending webhook can be rejected.');

            return;
        }

        if (trim($reason) === '') {
            $this->addError('webhook', 'A rejection reason is required.');

            return;
        }

        $webhook->update([
            'status' => 'rejected',
            'rejected_reason' => $reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    public function promptReject(int $id): void
    {
        $this->rejectingWebhookId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingWebhookId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(): void
    {
        if (! $this->rejectingWebhookId) {
            return;
        }

        $this->rejectWebhook($this->rejectingWebhookId, $this->rejectReason);
        $this->rejectingWebhookId = null;
        $this->rejectReason = '';
    }

    public function render(): View
    {
        $webhooks = DocumentWebhook::where('document_id', $this->document->id)->latest()->get();

        return view('livewire.documents.webhook-manager', compact('webhooks'));
    }

    public function forgetSecret(): void
    {
        $this->revealedSecret = null;
    }
}
