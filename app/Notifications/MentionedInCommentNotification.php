<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The bell only - see CommentPostedNotification's docblock for why this
 * doesn't implement ShouldQueue. MentionedInCommentEmailNotification
 * handles the delayed, cancellable email.
 */
class MentionedInCommentNotification extends Notification
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
            'type' => 'mention',
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'document_title' => $this->document->title,
            'comment_id' => $this->comment->id,
            'mentioner' => $this->comment->user->name,
            'excerpt' => Str::limit($this->comment->content, 80),
            'url' => route('documents.edit', $this->document->uuid),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
