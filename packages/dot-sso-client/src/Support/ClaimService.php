<?php

namespace Dot\SsoClient\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The claim flow: a local account has this email but is unverified or not yet linked, so nothing is replaced and nothing
 * is detached. A one-time numeric code goes to the address on file through the platform's own mailer; only the correct
 * code links the account. Codes are stored as an HMAC, last ten minutes, allow five tries, and sending is rate limited
 * per email address and per IP address.
 */
class ClaimService
{
    private const SESSION = 'dotsso.claim';

    /** @param array{sub: string, email: string, name: string} $claims */
    public function begin(Request $request, Authenticatable $user, array $claims, ?string $deepLink, string $idToken): RedirectResponse|Response
    {
        if (! $this->mayStartSend($request, $claims['email'])) {
            return SsoError::page($request, 'rate_limited', ['detail' => 'claim send limit', 'email' => Sso::tag($claims['email'])]);
        }

        $claimId = Pending::random(24);
        $request->session()->put(self::SESSION, [
            'id' => $claimId,
            'sub' => $claims['sub'],
            'email' => $claims['email'],
            'name' => $claims['name'],
            'user_key' => (string) $user->getAuthIdentifier(),
            'deep_link' => $deepLink,
            'id_token' => $idToken,
            'created' => now()->getTimestamp(),
        ]);

        $this->issue($request, $claimId, $user, $claims['email'], $claims['sub']);

        Sso::log('claim_started', ['email' => Sso::tag($claims['email'])]);

        return redirect()->route('dotsso.claim')->header('Cache-Control', 'no-store');
    }

    /** Send a fresh code for the claim in the session. Returns false when the sending limit stopped it. */
    public function resend(Request $request): bool
    {
        $claim = $this->current($request);
        $user = $claim ? Sso::guard()->getProvider()->retrieveById($claim['user_key']) : null;

        if (! $claim || ! $user) {
            return false;
        }

        if (! $this->mayStartSend($request, $claim['email'])) {
            return false;
        }

        $this->issue($request, $claim['id'], $user, $claim['email'], $claim['sub']);

        return true;
    }

    /** @return array<string, mixed>|null */
    public function current(Request $request): ?array
    {
        $claim = $request->session()->get(self::SESSION);

        if (! is_array($claim) || now()->getTimestamp() - (int) ($claim['created'] ?? 0) > (int) config('dotsso.claim.ttl_seconds', 600) * 3) {
            return null;
        }

        return $claim;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION);
    }

    /**
     * Check a code. Returns null on success or the error code ('claim_missing', 'claim_expired', 'claim_locked',
     * 'rate_limited', 'wrong:<tries left>').
     */
    public function check(Request $request, string $code): ?string
    {
        $claim = $this->current($request);

        if (! $claim) {
            return 'claim_missing';
        }

        $ipKey = 'dotsso-claim-verify:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('dotsso.claim.verify_per_ip', 30))) {
            return 'rate_limited';
        }

        RateLimiter::hit($ipKey, (int) config('dotsso.claim.window_seconds', 900));

        $row = DB::table('dotsso_claims')->where('claim_id', $claim['id'])->whereNull('consumed_at')->first();

        if (! $row || strtotime((string) $row->expires_at) < now()->getTimestamp()) {
            return 'claim_expired';
        }

        $max = (int) config('dotsso.claim.max_attempts', 5);

        if ($row->attempts >= $max) {
            return 'claim_locked';
        }

        // The attempt is counted BEFORE the comparison, so a wrong guess can never be taken back.
        DB::table('dotsso_claims')->where('id', $row->id)->increment('attempts');

        if (! hash_equals($row->code_hash, $this->hash($claim['id'], $code))) {
            $left = $max - ($row->attempts + 1);
            Sso::log('claim_wrong_code', ['email' => Sso::tag($claim['email']), 'left' => max(0, $left)], 'warning');

            return $left <= 0 ? 'claim_locked' : 'wrong:'.$left;
        }

        DB::table('dotsso_claims')->where('id', $row->id)->update(['consumed_at' => now()]);

        return null;
    }

    /** After a correct code: link and sign in. Nothing about the local account is replaced. */
    public function complete(Request $request, SsoCompletion $finish): RedirectResponse|Response
    {
        $claim = $this->current($request);
        $provisioner = Sso::provisioner();
        $user = $claim ? Sso::guard()->getProvider()->retrieveById($claim['user_key']) : null;

        // The address must still belong to that same local account, and it must not have been linked to someone else meanwhile.
        $sameAccount = $user && ($byEmail = $provisioner->findByEmail($claim['email'])) && (string) $byEmail->getAuthIdentifier() === (string) $user->getAuthIdentifier();
        $linked = $user ? $provisioner->uuidOf($user) : null;

        if (! $sameAccount || ($linked !== null && $linked !== '' && ! hash_equals($linked, $claim['sub']))) {
            $this->forget($request);

            return SsoError::page($request, 'account_linked_elsewhere', ['detail' => 'claim target changed']);
        }

        $provisioner->link($user, $claim['sub']);
        $provisioner->confirmEmail($user);

        Sso::log('claim_completed', ['email' => Sso::tag($claim['email'])]);

        return $finish->finish($request, $user, [
            'sub' => $claim['sub'], 'email' => $claim['email'], 'name' => $claim['name'],
        ], DeepLink::clean($claim['deep_link'] ?? null, $request), (string) $claim['id_token'], 'claim');
    }

    private function mayStartSend(Request $request, string $email): bool
    {
        $window = (int) config('dotsso.claim.window_seconds', 900);
        $emailKey = 'dotsso-claim-send:email:'.sha1(strtolower($email));
        $ipKey = 'dotsso-claim-send:ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($emailKey, (int) config('dotsso.claim.send_per_email', 3))
            || RateLimiter::tooManyAttempts($ipKey, (int) config('dotsso.claim.send_per_ip', 10))) {
            return false;
        }

        RateLimiter::hit($emailKey, $window);
        RateLimiter::hit($ipKey, $window);

        return true;
    }

    private function issue(Request $request, string $claimId, Authenticatable $user, string $email, string $sub): void
    {
        $length = (int) config('dotsso.claim.code_length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $ttl = (int) config('dotsso.claim.ttl_seconds', 600);

        // One live code per claim: sending a new one cancels the old.
        DB::table('dotsso_claims')->where('claim_id', $claimId)->delete();
        DB::table('dotsso_claims')->insert([
            'claim_id' => $claimId,
            'user_key' => (string) $user->getAuthIdentifier(),
            'email' => $email,
            'sub' => $sub,
            'code_hash' => $this->hash($claimId, $code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Housekeeping: spent and expired rows are not kept.
        DB::table('dotsso_claims')->where('expires_at', '<', now()->subDay())->delete();

        try {
            Sso::provisioner()->sendClaimCode($user, $code, (int) ceil($ttl / 60));
        } catch (\Throwable $e) {
            Sso::log('claim_mail_failed', ['email' => Sso::tag($email), 'error' => class_basename($e)], 'error');
        }
    }

    private function hash(string $claimId, string $code): string
    {
        return hash_hmac('sha256', $claimId.':'.$code, (string) config('app.key'));
    }
}
