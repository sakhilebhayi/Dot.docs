<?php

namespace App\Events;

use App\Models\Document;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly User $editor,
        public readonly string $content,
        public readonly array $json,
        public readonly int $version,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('document.'.$this->document->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'document.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->id,
            'content' => $this->content,
            'json' => $this->json,
            'version' => $this->version,
            'editor' => [
                'id' => $this->editor->id,
                'name' => $this->editor->name,
                'avatar' => $this->editor->profile_photo_url,
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
