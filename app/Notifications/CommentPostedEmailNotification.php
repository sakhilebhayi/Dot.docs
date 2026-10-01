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
 * The email half of a comment notification. CommentPostedNotification
 * handles the instant bell update; this one is queued with a short delay
 * and cancels itself if the recipient has already read that bell
 * notification by the time it's due to send - so an active user never
 * gets double-notified for the same comment.
 */
class CommentPostedEmailNotification extends Notification implements ShouldQueue
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

    /**
     * Runs when the queued job is processed (after the delay), not when it
     * is dispatched. Matched on the bell row's type + comment id rather
     * than its own id: NotificationSender assigns every notification a
     * fresh UUID at send time, so the two dispatches cannot share one.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable->notifications()
            ->where('data->type', 'comment')
            ->where('data->comment_id', $this->comment->id)
            ->whereNotNull('read_at')
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New comment on "'.$this->document->title.'"')
            ->line($this->comment->user->name.' commented on your document.')
            ->line('"'.Str::limit($this->comment->content, 120).'"')
            ->action('View Document', route('documents.edit', $this->document->uuid));
    }
}
