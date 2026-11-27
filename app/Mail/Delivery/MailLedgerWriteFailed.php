<?php

namespace App\Mail\Delivery;

use RuntimeException;
use Throwable;

/**
 * Reported when an email went but its sent-mail record could not be written (R2-11). The email is not affected; a retry
 * of the same job could send it again, so somebody should know. Names the kind of email and the reason, never the
 * address or the content.
 */
class MailLedgerWriteFailed extends RuntimeException
{
    public function __construct(public readonly string $kind, Throwable $reason)
    {
        parent::__construct(sprintf(
            'A "%s" email was sent but its sent-mail record was not written (%s).',
            $kind,
            class_basename($reason),
        ), 0, $reason);
    }

    /** Reported in one call, never thrown. (Not named report(): Laravel calls that on an exception as its own reporter.) */
    public static function raise(string $kind, Throwable $reason): void
    {
        try {
            report(new self(class_basename($kind), $reason));
        } catch (Throwable) {
            // Reporting must not stop mail either.
        }
    }
}
