<?php

namespace App\Events;

use App\Models\Document;
use App\Models\User;
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
class UserLeftDocument implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly User $user,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('document.'.$this->document->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.left';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
        ];
    }
}
