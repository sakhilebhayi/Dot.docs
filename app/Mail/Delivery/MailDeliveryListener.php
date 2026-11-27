<?php

namespace App\Mail\Delivery;

use App\Notifications\CommentEmailNotification;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Symfony\Component\Mime\Message;

/**
 * Sends each queued email once (R2-4 / E-10).
 *
 * Notifications: Laravel gives a notification its id once, when it is queued, so every retry of the job has the same
 * one. Mailables: the X-Dot-Mail-Ref header from SendsOnce. Returning false from a "sending" event is Laravel's own way
 * to stop that send; nothing else is touched.
 */
class MailDeliveryListener
{
    public const REF_HEADER = 'X-Dot-Mail-Ref';

    public function __construct(private MailLedger $ledger) {}

    public function notificationSending(NotificationSending $event): ?bool
    {
        $ref = $this->notificationRef($event->notification, $event->channel);

        return $ref !== null && $this->ledger->alreadySent($ref) ? false : null;
    }

    public function notificationSent(NotificationSent $event): void
    {
        $ref = $this->notificationRef($event->notification, $event->channel);
        if ($ref === null) {
            return;
        }

        $this->ledger->record($ref, $event->notification::class);

        // The hourly comment-mail cap counts emails that went, not attempts (E-10).
        if ($event->notification instanceof CommentEmailNotification) {
            $event->notification->countAgainstCap($event->notifiable);
        }
    }

    public function messageSending(MessageSending $event): ?bool
    {
        $ref = $this->messageRef($event->message->getHeaders()->get(self::REF_HEADER)?->getBodyAsString());

        return $ref !== null && $this->ledger->alreadySent($ref) ? false : null;
    }

    public function messageSent(MessageSent $event): void
    {
        $original = $event->sent->getOriginalMessage();
        $ref = $original instanceof Message
            ? $this->messageRef($original->getHeaders()->get(self::REF_HEADER)?->getBodyAsString())
            : null;
        if ($ref !== null) {
            $this->ledger->record($ref, (string) ($event->data['__laravel_mailable'] ?? 'mailable'));
        }
    }

    private function notificationRef(object $notification, string $channel): ?string
    {
        $id = $notification->id ?? null;

        return $channel === 'mail' && is_string($id) && $id !== '' ? 'n:'.$id : null;
    }

    private function messageRef(?string $header): ?string
    {
        return $header !== null && str_starts_with($header, 'm:') ? $header : null;
    }
}
