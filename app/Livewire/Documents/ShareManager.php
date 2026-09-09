<?php

namespace App\Livewire\Documents;

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
        session()->flash('status', 'Share settings saved.');
    }

    public function clearPassword(): void
    {
        $this->authorize('manage', $this->document);
        $this->document->update(['share_password' => null]);
        $this->document->refresh();
        $this->showPasswordSet = false;
    }

    public function render()
    {
        return view('livewire.documents.share-manager')
            ->layout('layouts.app')
            ->title('Sharing for '.$this->document->title);
    }
}
