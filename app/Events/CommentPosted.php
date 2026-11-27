<?php

namespace App\Events;

use App\Models\Comment;
use App\Models\Document;
use App\Support\LiveBroadcast;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow, not ShouldBroadcast: a plain ShouldBroadcast event is
 * pushed onto the queue, and this app's queue is only drained by a
 * once-a-minute cron worker (see routes/console.php) - a "live" event that
 * arrives up to a minute late is worse than none. Every dispatch site
 * already wraps this in a try/catch for an unreachable Reverb.
 */
class CommentPosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('document.'.$this->document->id)];
    }

    public function broadcastAs(): string
    {
        return 'comment.posted';
    }

    /** Skipped when the broadcaster reaches nobody (`null` / `log`); listeners in the app still run. */
    public function broadcastWhen(): bool
    {
        return LiveBroadcast::available();
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'comment' => [
                'id' => $this->comment->id,
                'content' => $this->comment->content,
                'parent_id' => $this->comment->parent_id,
                'selection_text' => $this->comment->selection_text,
                'resolved_at' => $this->comment->resolved_at,
                'created_at' => $this->comment->created_at?->toISOString(),
                // A deleted account's comments stay, shown as "Former member" (EA-2): never read through a null user.
                'user' => [
                    'id' => $this->comment->user?->id,
                    'name' => $this->comment->user->name ?? 'Former member',
                    'avatar' => $this->comment->user?->profile_photo_url,
                ],
            ],
        ];
    }
}
