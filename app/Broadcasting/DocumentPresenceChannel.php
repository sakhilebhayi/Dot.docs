<?php

namespace App\Broadcasting;

use App\Models\Document;
use App\Models\User;

class DocumentPresenceChannel
{
    /**
     * Authenticate the user's access to the channel and return presence data.
     * Returning false denies access; returning an array admits them and
     * provides the member data visible to other channel members.
     */
    public function join(User $user, int $documentId): array|bool
    {
        $document = Document::find($documentId);

        if (! $document) {
            return false;
        }

        // Owner, team member or collaborator - DocumentPolicy::view(). A
        // published document's readers use the public page, not the editor.
        if ($document->owner_id === $user->id) {
            return $this->memberData($user);
        }

        if ($document->team_id && $user->belongsToTeam($document->team)) {
            return $this->memberData($user);
        }

        if ($document->collaborators()->where('user_id', $user->id)->exists()) {
            return $this->memberData($user);
        }

        return false;
    }

    private function memberData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->profile_photo_url,
        ];
    }
}
