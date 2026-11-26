<?php

namespace App\Notifications\Account;

use App\Models\Document;
use Illuminate\Notifications\Notification;

/**
 * The bell half of DocumentSharedWithYou: written inline, like the comment
 * bell (database only; production has no websocket listener).
 */
class DocumentSharedBell extends Notification
{
    public function __construct(
        public readonly Document $document,
        public readonly string $actorName,
        public readonly bool $roleChanged = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->roleChanged ? 'access_changed' : 'shared',
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'document_title' => $this->document->title,
            'actor' => $this->actorName,
            'url' => route('documents.edit', $this->document->uuid),
        ];
    }
}
