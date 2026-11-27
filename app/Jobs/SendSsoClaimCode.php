<?php

namespace App\Jobs;

use App\Mail\Delivery\MailNotDelivered;
use App\Mail\SsoClaimCodeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the SSO claim code from the `mail` queue (R2-4 / E-05), so a slow mail server never holds the sign-in page;
 * the cron worker sends it within the minute, well inside the code's ten minutes.
 *
 * Encrypted while it waits (ShouldBeEncrypted): the jobs table must not hold a working code or the address readably.
 * The mailable is made once, here, so its X-Dot-Mail-Ref is the same on every retry and it goes once (SendsOnce).
 * A retry only sends a code that is still the live one: when the first try failed and the person asked for a new code
 * meanwhile, a late copy of the old one would land on top of the new one and be refused when typed.
 */
class SendSsoClaimCode implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60];

    public SsoClaimCodeMail $mail;

    public function __construct(public int $userId, public string $email, string $name, string $code, int $minutes)
    {
        $this->mail = new SsoClaimCodeMail($name, $code, $minutes);
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        if (! $this->codeIsStillLive()) {
            return;
        }

        Mail::to($this->email)->send($this->mail);
    }

    public function failed(Throwable $e): void
    {
        MailNotDelivered::raise('SsoClaimCode', $e);
    }

    /**
     * The dot-sso-client package keeps one row per open claim with an HMAC of "claim_id:code" under the app key
     * (ClaimService::hash()); the code is live while one of this person's unexpired rows matches it.
     */
    private function codeIsStillLive(): bool
    {
        $code = $this->mail->code;

        return DB::table('dotsso_claims')
            ->where('user_key', (string) $this->userId)
            ->where('expires_at', '>', now())
            ->get(['claim_id', 'code_hash'])
            ->contains(fn (object $row) => hash_equals(
                (string) $row->code_hash,
                hash_hmac('sha256', $row->claim_id.':'.$code, (string) config('app.key')),
            ));
    }
}
