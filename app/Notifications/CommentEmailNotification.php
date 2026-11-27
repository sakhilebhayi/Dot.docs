<?php

namespace App\Notifications;

use App\Http\Controllers\CommentEmailPreferenceController;
use App\Mail\Delivery\MailNotDelivered;
use App\Models\Comment;
use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * The email half of a comment notification.
 *
 * The bell (CommentPostedNotification, MentionedInCommentNotification) is
 * instant. The email is queued with a short delay and only goes out if, by
 * then, the recipient still has not read that bell notification - so
 * somebody active in the app is never told twice about the same comment.
 */
abstract class CommentEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** How long the recipient gets to see it in the app first. */
    private const DELAY_MINUTES = 2;

    /** No more comment emails than this to one person in an hour. */
    private const MAX_PER_RECIPIENT_PER_HOUR = 30;

    /** One momentary sendmail failure should not lose the email for good. */
    public int $tries = 3;

    /**
     * The comment or its document can be deleted inside the delay. The
     * right outcome is no email and no trace, not a failed job.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    /**
     * The bell notification this email is the other half of.
     *
     * @return class-string<Notification>
     */
    abstract protected function bellNotification(): string;

    /** Whether this person may still be sent the comment's text. */
    abstract protected function mayReceive(User $user): bool;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['mail' => 'mail'];
    }

    /** @return array<string, \DateTimeInterface> */
    public function withDelay(object $notifiable): array
    {
        return ['mail' => now()->addMinutes(self::DELAY_MINUTES)];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Every comment email carries its own way out (EA-3 / E-06): a line at
     * the foot and the List-Unsubscribe headers mail clients turn into a
     * one-click button (RFC 8058).
     */
    protected function withUnsubscribe(MailMessage $message, object $user): MailMessage
    {
        if (! $user instanceof User) {
            return $message;
        }

        $link = CommentEmailPreferenceController::link($user);

        // Our own signed address, so a Markdown link is safe here (it is not user text).
        return $message
            ->line(new HtmlString('[Stop comment emails]('.$link.')'))
            ->withSymfonyMessage(function (Email $email) use ($link) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$link.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    /** Last retry failed: reported, so it is logged, counted and a person is mailed (R2-4 / E-02). */
    public function failed(\Throwable $e): void
    {
        MailNotDelivered::raise(class_basename(static::class), $e);
    }

    /**
     * Called by MailDeliveryListener once the email has gone (R2-4 / E-10): the hourly cap counts emails sent, so a
     * try that failed, or one stopped by a check, does not use up one of the person's thirty.
     */
    public function countAgainstCap(object $notifiable): void
    {
        if ($notifiable instanceof User) {
            RateLimiter::hit(self::capKey($notifiable), 3600);
        }
    }

    private static function capKey(User $user): string
    {
        return 'comment-email:'.$user->id;
    }

    /**
     * Runs when the queued job is processed - after the delay - not when it
     * is dispatched, so every check here sees the state at send time.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        // The person switched comment emails off (EA-3 / E-06).
        if ($notifiable->comment_emails === false) {
            return false;
        }

        if ($this->alreadySeenInTheBell($notifiable)) {
            return false;
        }

        // Access can be taken away during the delay.
        if (! $this->mayReceive($notifiable)) {
            return false;
        }

        // Only looked at here; the slot is used when the email has actually gone (countAgainstCap).
        return ! RateLimiter::tooManyAttempts(self::capKey($notifiable), self::MAX_PER_RECIPIENT_PER_HOUR);
    }

    /**
     * Matched on the bell row's class + comment id rather than its own id:
     * NotificationSender gives every notification a fresh UUID at send
     * time, so the bell and the email cannot share one.
     *
     * The comment id is compared in PHP, not with a JSON path in SQL:
     * `notifications.data` is a TEXT column, and `data->comment_id` only
     * works on it in SQLite - PostgreSQL refuses the operator outright. The
     * created_at bound keeps the rows fetched to the few written since
     * this comment was.
     */
    private function alreadySeenInTheBell(User $user): bool
    {
        return $user->notifications()
            ->where('type', $this->bellNotification())
            ->whereNotNull('read_at')
            ->where('created_at', '>=', $this->comment->created_at->copy()->subMinute())
            ->get()
            ->contains(fn (DatabaseNotification $bell) => ($bell->data['comment_id'] ?? null) === $this->comment->id);
    }
}
