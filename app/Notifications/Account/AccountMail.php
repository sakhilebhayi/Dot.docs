<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One email about an account or an access change (EA-3, enterprise audit E-04).
 *
 * Queued on the `mail` queue the cron worker drains every minute, never sent
 * inside the request that caused it (E-05): a slow SMTP server must not stall
 * a share or a password change. Not subject to the comment-mail preference:
 * a person always hears when their access or their account changes.
 *
 * Text from users goes in through MailText (see .ai/rules/notifications.md).
 */
abstract class AccountMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** One momentary SMTP failure should not lose the email for good. */
    public int $tries = 3;

    /** A document deleted before the worker runs: no email, no failed job. */
    public bool $deleteWhenMissingModels = true;

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

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * The last retry failed: the job lands in failed_jobs (counted by the
     * Guardian queue check) and this line reaches the error log, which the
     * Guardian error-rate check reads. No address, no content (E-02).
     */
    public function failed(Throwable $e): void
    {
        Log::error('Account email could not be sent.', ['notice' => static::class, 'error' => $e::class]);
    }
}
