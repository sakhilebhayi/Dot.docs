<?php

namespace App\Notifications\Access;

use App\Models\AccessRequest;
use Illuminate\Notifications\Notification;

/** The bell half of AccessRequested: written inline, like the other bells (database only). */
class AccessRequestedBell extends Notification
{
    public function __construct(public readonly AccessRequest $request) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $document = $this->request->document;

        return [
            'type' => 'access_requested',
            'document_id' => $document->id,
            'document_uuid' => $document->uuid,
            'document_title' => $document->title,
            'actor' => $this->request->requester->name,
            'url' => route('documents.share', $document->uuid).'#access-requests',
        ];
    }
}
