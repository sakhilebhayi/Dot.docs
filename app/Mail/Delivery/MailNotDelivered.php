<?php

namespace App\Mail\Delivery;

use RuntimeException;
use Throwable;

/**
 * Reported when a queued email has used all its tries (R2-4 / E-02). Going through report() means it is counted for
 * Guardian's error-rate check and mailed to OPS_ALERT_EMAIL by ErrorAlert, once an hour for each kind of email. It
 * names the kind and the reason, never the address or the content.
 */
class MailNotDelivered extends RuntimeException
{
    public function __construct(public readonly string $kind, Throwable $reason)
    {
        parent::__construct(sprintf(
            'A "%s" email was given up after its last try (%s). The job is kept in failed_jobs for 30 days: '
            .'"php artisan queue:failed" lists it and "php artisan queue:retry <id>" sends it again.',
            $kind,
            class_basename($reason),
        ), 0, $reason);
    }

    /** Reported in one call: the log line, the error count and the alert. (Not named report(): Laravel calls that on an exception as its own reporter.) */
    public static function raise(string $kind, Throwable $reason): void
    {
        report(new self($kind, $reason));
    }
}
