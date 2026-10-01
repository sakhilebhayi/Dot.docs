<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The bell only - database + broadcast, dispatched synchronously (this
 * class does NOT implement ShouldQueue) so the bell updates the instant a
 * comment is posted, with no queue hop in between. The email side lives in
 * CommentPostedEmailNotification, which IS queued and delayed.
 */
class CommentPostedNotification extends Notification
{
    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'comment',
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'document_title' => $this->document->title,
            'comment_id' => $this->comment->id,
            'commenter' => $this->comment->user->name,
            'excerpt' => Str::limit($this->comment->content, 80),
            'url' => route('documents.edit', $this->document->uuid),
        ];
    }

    /**
     * onConnection('sync') is what actually makes the bell live. Dropping
     * ShouldQueue from this class only makes the `database` channel inline:
     * the broadcast channel wraps its message in a ShouldBroadcast event,
     * which Laravel pushes onto the DEFAULT queue regardless - and that
     * queue is drained once a minute here.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
