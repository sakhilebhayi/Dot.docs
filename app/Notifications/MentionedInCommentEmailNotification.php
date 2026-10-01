<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The email half of a mention notification - see
 * CommentPostedEmailNotification's docblocks for the delay/cancel mechanism.
 */
class MentionedInCommentEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function withDelay(object $notifiable): array
    {
        return ['mail' => now()->addMinutes(2)];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable->notifications()
            ->where('data->type', 'mention')
            ->where('data->comment_id', $this->comment->id)
            ->whereNotNull('read_at')
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->comment->user->name.' mentioned you in "'.$this->document->title.'"')
            ->line($this->comment->user->name.' mentioned you in a comment.')
            ->line('"'.Str::limit($this->comment->content, 120).'"')
            ->action('View Document', route('documents.edit', $this->document->uuid));
    }
}
