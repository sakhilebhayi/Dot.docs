<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determine whether the user can view any documents.
     * Any authenticated user can see their own documents list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the document.
     * Owner, team members, collaborators, or public documents.
     *
     * Answered from the database every time, never from a cache. This used
     * to be remembered per user for fifteen minutes, and nothing that
     * changes access forgot it: a removed collaborator or team member kept
     * getting in, and somebody refused once stayed refused after being
     * invited. Access changes in too many places to chase with forget()
     * calls - Jetstream's own team screens among them - and the lookup is
     * one indexed query, cheaper than reading a database-backed cache.
     */
    public function view(User $user, Document $document): bool
    {
        if ($document->is_public) {
            return true;
        }

        if ($document->owner_id === $user->id) {
            return true;
        }

        if ($document->team_id && $user->belongsToTeam($document->team)) {
            return true;
        }

        return $document->collaborators()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine whether the user can create documents.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the document.
     * Owner, team owner or admin or editor, or a collaborator with the editor
     * or admin role. The grants ADD UP: a team viewer who is an editor
     * collaborator on this document may update it.
     *
     * Never cached - see view().
     */
    public function update(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id
            || in_array($this->teamRoleKey($user, $document), ['admin', 'editor'], true)
            || $this->collaboratorHasRole($user, $document, ['editor', 'admin']);
    }

    /**
     * Determine whether the user can delete the document.
     * Only the owner or a team admin (the team's owner counts as one).
     */
    public function delete(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id || $this->teamRoleKey($user, $document) === 'admin';
    }

    /**
     * Determine whether the user can restore the document (soft-deleted).
     */
    public function restore(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the document.
     */
    public function forceDelete(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id;
    }

    /**
     * Manage: change share settings, password, expiration (owner + admins only,
     * the team's owner counts as an admin).
     */
    public function manage(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id || $this->teamRoleKey($user, $document) === 'admin';
    }

    /**
     * Share: invite collaborators (owner, team owner or admin, editors, and
     * collaborators with the editor or admin role: the same people who may update).
     */
    public function share(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    /**
     * The person's role on the document's team: 'admin' for the team's owner
     * (Jetstream calls that role 'owner'; it can do everything an admin can),
     * the member's own role key otherwise, null when the document has no team
     * or the person is not on it.
     */
    private function teamRoleKey(User $user, Document $document): ?string
    {
        if (! $document->team_id || ! $document->team || ! $user->belongsToTeam($document->team)) {
            return null;
        }

        if ($user->ownsTeam($document->team)) {
            return 'admin';
        }

        return $user->teamRole($document->team)?->key;
    }

    /** @param  list<string>  $roles */
    private function collaboratorHasRole(User $user, Document $document, array $roles): bool
    {
        return $document->collaborators()->where('user_id', $user->id)->whereIn('role', $roles)->exists();
    }
}
