<?php

namespace App\Livewire\Documents;

use App\Audit\AuditLogger;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class ShareManager extends Component
{
    use AuthorizesRequests;

    public Document $document;

    public string $inviteEmail = '';

    public string $inviteRole = 'viewer';

    public string $publicLink = '';

    /**
     * The published address's name. Independent of `is_public`: a writer can
     * reserve a slug while the document is still private, and publishing is
     * what makes /d/{slug} answer.
     */
    public string $slug = '';

    public string $publishedLink = '';

    // Share link options
    public string $sharePassword = '';

    public string $shareExpiresAt = '';

    public bool $showPasswordSet = false;

    public function mount(string $uuid): void
    {
        $this->document = Document::with('collaborators.user')->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $this->document);
        $this->publicLink = $this->document->is_public
            ? route('documents.shared', $this->document->uuid)
            : '';
        $this->shareExpiresAt = $this->document->share_expires_at
            ? $this->document->share_expires_at->format('Y-m-d\TH:i')
            : '';
        $this->slug = $this->document->slug ?? '';
        $this->showPasswordSet = (bool) $this->document->share_password;
        $this->refreshPublishedLink();
    }

    public function saveSlug(): void
    {
        $this->authorize('update', $this->document);

        $this->validate([
            // Lower case, digits and hyphens only - the slug is the whole
            // public address, so it has to survive being typed and pasted.
            // The document's own id is excused from the unique rule, so
            // re-saving an unchanged slug is not a collision with itself.
            'slug' => ['nullable', 'regex:/^[a-z0-9-]{4,80}$/', 'unique:documents,slug,'.$this->document->id],
        ], [
            'slug.regex' => 'Use four to eighty lower-case letters, digits or hyphens.',
            'slug.unique' => 'Somebody has already taken that address.',
        ]);

        $this->document->update(['slug' => $this->slug !== '' ? $this->slug : null]);
        $this->document->refresh();
        $this->refreshPublishedLink();
        $this->auditShareChange(['slug' => $this->document->slug]);
        session()->flash('status', $this->document->slug
            ? 'The address is saved.'
            : 'The address is cleared.');
    }

    private function refreshPublishedLink(): void
    {
        $this->publishedLink = $this->document->slug
            ? route('documents.published', $this->document->slug)
            : '';
    }

    public function invite(): void
    {
        $this->authorize('share', $this->document);
        $this->validate([
            'inviteEmail' => 'required|email|exists:users,email',
            'inviteRole' => 'required|in:viewer,editor,admin',
        ]);

        $user = User::where('email', $this->inviteEmail)->firstOrFail();

        DocumentCollaborator::updateOrCreate(
            ['document_id' => $this->document->id, 'user_id' => $user->id],
            ['role' => $this->inviteRole]
        );

        $this->inviteEmail = '';
        $this->inviteRole = 'viewer';
        $this->document->load('collaborators.user');
    }

    public function removeCollaborator(int $collaboratorId): void
    {
        $this->authorize('share', $this->document);
        DocumentCollaborator::where('id', $collaboratorId)
            ->where('document_id', $this->document->id)
            ->delete();

        $this->document->load('collaborators.user');
    }

    public function togglePublicLink(): void
    {
        $this->authorize('manage', $this->document);
        $this->document->update(['is_public' => ! $this->document->is_public]);
        $this->document->refresh();
        $this->publicLink = $this->document->is_public
            ? route('documents.shared', $this->document->uuid)
            : '';
        $this->refreshPublishedLink();
        $this->auditShareChange(['is_public' => (bool) $this->document->is_public]);
    }

    public function saveShareOptions(): void
    {
        $this->authorize('manage', $this->document);
        $this->validate([
            'sharePassword' => 'nullable|string|min:4|max:72',
            'shareExpiresAt' => 'nullable|date|after:now',
        ]);

        $updates = [
            'share_expires_at' => $this->shareExpiresAt ?: null,
        ];

        if ($this->sharePassword !== '') {
            $updates['share_password'] = Hash::make($this->sharePassword);
        }

        $this->document->update($updates);
        $this->document->refresh();
        $this->sharePassword = '';
        $this->showPasswordSet = (bool) $this->document->share_password;
        $this->auditShareChange([
            'expires_at' => $this->document->share_expires_at?->toIso8601String(),
            'password_set' => $this->showPasswordSet,
        ]);
        session()->flash('status', 'Share settings saved.');
    }

    public function clearPassword(): void
    {
        $this->authorize('manage', $this->document);
        $this->document->update(['share_password' => null]);
        $this->document->refresh();
        $this->showPasswordSet = false;
        $this->auditShareChange(['password_set' => false]);
    }

    /**
     * One `share.updated` row per change to who can reach this document -
     * is_public, the slug, the link password or its expiry. The context says
     * WHAT the setting now is; it never carries the password itself, hashed
     * or otherwise, because audit rows are read by every team admin.
     *
     * @param  array<string,mixed>  $context
     */
    private function auditShareChange(array $context): void
    {
        app(AuditLogger::class)->record('share.updated', $this->document, $context);
    }

    public function render()
    {
        return view('livewire.documents.share-manager')
            ->layout('layouts.app')
            ->title('Sharing for '.$this->document->title);
    }
}
