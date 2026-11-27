<?php

namespace App\Mail\Delivery;

use Illuminate\Support\Facades\DB;

/**
 * The sent-mail record (R2-4 / E-10). A queued email carries a reference that stays the same across its retries: a
 * notification's id (given once, when it is queued) or a mailable's X-Dot-Mail-Ref header (SendsOnce). Before the
 * mailer sends, MailDeliveryListener asks here whether that reference already went; after it sends, it records it.
 *
 * Never throws: a broken record must not stop mail, so a failed lookup counts as "not sent" and a failed write is lost.
 */
class MailLedger
{
    public function alreadySent(string $ref): bool
    {
        try {
            return DB::table('sent_mails')->where('ref', $ref)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function record(string $ref, string $kind): void
    {
        try {
            DB::table('sent_mails')->insertOrIgnore(['ref' => $ref, 'kind' => $kind, 'sent_at' => now()]);
        } catch (\Throwable) {
            // The email went; only its record is missing.
        }
    }
}
