<?php

namespace App\Livewire\Documents;

use App\Audit\AuditLogger;
use App\Models\AccessRequest;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Notifications\Account\DocumentAccessRemoved;
use App\Notifications\Account\DocumentPublishedByAnother;
use App\Notifications\Account\DocumentSharedBell;
use App\Notifications\Account\DocumentSharedWithYou;
use App\Support\SignedIn;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ShareManager extends Component
{
    use AuthorizesRequests;
    use SignedIn;

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

    /** What the last access-request decision said, shown beside the list (R2-3b). */
    public string $accessNotice = '';

    /**
     * Open requests for access to this document (R2-3b / U-06), to anyone who may share it. Oldest first: the person
     * who has waited longest is decided first.
     *
     * @return Collection<int, AccessRequest>
     */
    #[Computed]
    public function accessRequests(): Collection
    {
        if (! $this->actor()->can('share', $this->document)) {
            return new Collection;
        }

        return AccessRequest::where('document_id', $this->document->id)
            ->where('status', AccessRequest::PENDING)
            ->with('requester:id,name,email')
            ->oldest()
            ->get();
    }

    /**
     * Let them in, as a reader or an editor (the admin role stays a deliberate invitation above). They hear about
     * it the way any share is told: the bell at once and an email.
     */
    public function grantAccess(int $requestId, string $role = 'viewer'): void
    {
        $this->authorize('share', $this->document);
        abort_unless(in_array($role, ['viewer', 'editor'], true), 422);

        $accessRequest = $this->pendingRequest($requestId);
        $requester = $accessRequest->requester;
        $actor = $this->actor();
        $notify = false;
        $settled = false;

        DB::transaction(function () use ($accessRequest, $requester, $role, &$notify, &$settled) {
            // Only the decision that closes it acts: a second sharer clicking at the same moment changes nothing.
            if (! $this->close($accessRequest, AccessRequest::GRANTED, $role)) {
                return;
            }
            $settled = true;

            // Never lower anyone: somebody invited as an editor or admin since they asked keeps that role.
            $current = DocumentCollaborator::where('document_id', $this->document->id)->where('user_id', $requester->id)->value('role');
            if ($current === null || self::RANK[$role] > (self::RANK[$current] ?? 0)) {
                DocumentCollaborator::updateOrCreate(['document_id' => $this->document->id, 'user_id' => $requester->id], ['role' => $role]);
                $notify = true;
            }
            app(AuditLogger::class)->record('document.access_granted', $this->document, ['request_id' => $accessRequest->id, 'user_id' => $requester->id, 'role' => $role]);
        });

        if ($notify) {
            $requester->notify(new DocumentSharedBell($this->document, (string) $actor->name));
            $requester->notify(new DocumentSharedWithYou($this->document, $role, (string) $actor->name));
        }

        $this->document->load('collaborators.user');
        unset($this->accessRequests);
        $this->accessNotice = match (true) {
            ! $settled => 'Somebody else decided on that request a moment ago.',
            $notify => $requester->name.' can now '.($role === 'editor' ? 'edit' : 'read').' it. They have been told.',
            default => $requester->name.' already had more access than that; nothing changed.',
        };
    }

    /** No: the request is closed and nothing is sent to them. */
    public function declineAccess(int $requestId): void
    {
        $this->authorize('share', $this->document);

        $accessRequest = $this->pendingRequest($requestId);
        if ($this->close($accessRequest, AccessRequest::DECLINED)) {
            app(AuditLogger::class)->record('document.access_declined', $this->document, ['request_id' => $accessRequest->id, 'user_id' => $accessRequest->requester_id]);
        }

        unset($this->accessRequests);
        $this->accessNotice = 'Declined. Nothing was sent to '.$accessRequest->requester->name.'.';
    }

    /** Roles in order, so a grant can tell a raise from a cut. */
    private const RANK = ['viewer' => 1, 'editor' => 2, 'admin' => 3];

    /** Settle the request only if it is still open; true when this call settled it. */
    private function close(AccessRequest $accessRequest, string $status, ?string $role = null): bool
    {
        return AccessRequest::whereKey($accessRequest->id)->where('status', AccessRequest::PENDING)
            ->update(['status' => $status, 'role' => $role, 'decided_by' => Auth::id(), 'decided_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function pendingRequest(int $requestId): AccessRequest
    {
        $accessRequest = AccessRequest::where('document_id', $this->document->id)
            ->where('status', AccessRequest::PENDING)
            ->with('requester')
            ->find($requestId);
        abort_if($accessRequest === null, 404);

        return $accessRequest;
    }

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
        // Said in the page's banner, now (R2-9): a session flash from a Livewire action is never shown by that
        // action's own response and surfaced, stale, on some later page instead.
        $this->dispatch('banner-message', style: 'success', message: $this->document->slug
            ? 'The address is saved.'
            : 'The address is cleared.');
    }

    private function refreshPublishedLink(): void
    {
        $this->publishedLink = $this->document->slug
            ? route('documents.published', $this->document->slug)
            : '';
    }

    /** Shown under the invite form after a share or a role change. */
    public string $inviteNotice = '';

    public function invite(): void
    {
        $this->authorize('share', $this->document);
        $this->validate([
            'inviteEmail' => 'required|email|exists:users,email',
            'inviteRole' => 'required|in:viewer,editor,admin',
        ], [
            // R2-9: the default "The selected invite email is invalid." did not say what to do.
            'inviteEmail.exists' => 'Nobody with that email address has a Dot.docs account yet. Ask them to sign in to Dot.docs once, then invite them.',
            'inviteEmail.email' => 'That does not look like an email address.',
            'inviteEmail.required' => 'Enter the email address of the person to invite.',
        ]);

        // Handing out the admin role is a share-settings power: an editor may
        // invite, but not make anyone (themselves included) more than an editor.
        if ($this->inviteRole === 'admin') {
            $this->authorize('manage', $this->document);
        }

        $user = User::where('email', $this->inviteEmail)->firstOrFail();
        $before = DocumentCollaborator::where('document_id', $this->document->id)->where('user_id', $user->id)->value('role');

        // Taking the admin role away is the same power as giving it (R2-9): an editor could re-invite an admin as a
        // viewer and so demote them.
        if ($before === 'admin' && $this->inviteRole !== 'admin') {
            $this->authorize('manage', $this->document);
        }

        DocumentCollaborator::updateOrCreate(
            ['document_id' => $this->document->id, 'user_id' => $user->id],
            ['role' => $this->inviteRole]
        );

        // Who can reach the document is an access fact the audit log answers (R2-9): added, or a role changed.
        if ($before !== $this->inviteRole) {
            app(AuditLogger::class)->record(
                $before === null ? 'collaborator.added' : 'collaborator.role_changed',
                $this->document,
                array_filter(['user_id' => $user->id, 'role' => $this->inviteRole, 'previous_role' => $before]),
            );
        }

        // Asked for access earlier (R2-3b)? This invitation is the answer: the request is closed, not left open
        // for a later "Let them read" to undo.
        AccessRequest::where('document_id', $this->document->id)->where('requester_id', $user->id)
            ->where('status', AccessRequest::PENDING)
            ->update(['status' => AccessRequest::GRANTED, 'role' => $this->inviteRole, 'decided_by' => Auth::id(), 'decided_at' => now(), 'updated_at' => now()]);
        unset($this->accessRequests);

        // The person hears about it (EA-3 / E-04, F-11): the bell at once,
        // the email from the queue. Nothing when the role did not change or
        // when somebody adds themselves.
        if ($before !== $this->inviteRole && $user->id !== Auth::id()) {
            $changed = $before !== null;
            $actor = (string) $this->actor()->name;
            $user->notify(new DocumentSharedBell($this->document, $actor, $changed));
            $user->notify(new DocumentSharedWithYou($this->document, $this->inviteRole, $actor, $changed));
            $this->inviteNotice = $changed ? 'Access changed. They have been told.' : 'Shared. They have been told by email.';
        }

        $this->inviteEmail = '';
        $this->inviteRole = 'viewer';
        $this->document->load('collaborators.user');
    }

    public function removeCollaborator(int $collaboratorId): void
    {
        $this->authorize('share', $this->document);
        $collaborator = DocumentCollaborator::where('id', $collaboratorId)
            ->where('document_id', $this->document->id)
            ->with('user')
            ->first();

        if ($collaborator !== null) {
            // Removing an admin is a share-settings power too (R2-9), as giving the role is.
            if ($collaborator->role === 'admin') {
                $this->authorize('manage', $this->document);
            }

            $collaborator->delete();
            app(AuditLogger::class)->record('collaborator.removed', $this->document, ['user_id' => $collaborator->user_id, 'role' => $collaborator->role]);

            $removed = $collaborator->user;
            if ($removed instanceof User && $removed->id !== Auth::id()) {
                $removed->notify(new DocumentAccessRemoved($this->document->title, (string) $this->actor()->name));
            }
        }

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

        // A team admin can publish a document somebody else owns: the owner
        // is told (EA-3 / E-04).
        $owner = $this->document->owner;
        if ($owner instanceof User && $owner->id !== Auth::id()) {
            $owner->notify(new DocumentPublishedByAnother($this->document, (string) $this->actor()->name, (bool) $this->document->is_public));
        }
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
        $this->dispatch('banner-message', style: 'success', message: 'Share settings saved.');
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

    public function render(): View
    {
        // Each row shows the collaborator's name: loaded with them, also after a Livewire round trip has rehydrated
        // the document without its relations (R2-9; otherwise one query per row).
        $this->document->loadMissing('collaborators.user');

        return view('livewire.documents.share-manager')
            ->layout('layouts.app')
            ->title('Sharing for '.$this->document->title);
    }
}
