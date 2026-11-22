<?php

namespace Dot\SsoClient\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every failure ends on a plain page that says what happened in words, offers one next step, and never redirects on its
 * own, so a failure cannot turn into a loop. The reason code is logged and printed on the page as a support reference.
 */
class SsoError
{
    /** code => [http status, headline, what happened, can the person simply try again] */
    public const CATALOGUE = [
        'state_unknown' => [400, 'That sign-in link has already been used or has expired', 'For your security each sign-in link works once. Start again and it will take a few seconds.', true],
        'state_expired' => [400, 'That sign-in took too long', 'The sign-in was started more than ten minutes ago, so it was stopped. Start again.', true],
        'state_missing' => [400, 'That sign-in link is incomplete', 'Start again from the beginning.', true],
        'denied' => [403, 'The sign-in was not completed', 'The Dot sign-in did not let you through to this platform. If a message was shown there, follow it; otherwise start again.', true],
        'token_exchange_failed' => [502, 'We could not finish signing you in', 'The identity service did not accept the sign-in. Nothing was changed on your account. Try again in a minute.', true],
        'token_invalid' => [403, 'The sign-in answer could not be trusted', 'The answer from the identity service did not pass our checks, so it was refused. Nothing was changed on your account. Start again; if it repeats, contact support with the reference below.', true],
        'provider_unavailable' => [503, 'The identity service is not reachable right now', 'Try again in a minute. Your account is safe.', true],
        'misconfigured' => [503, 'Sign-in is not set up correctly on this platform', 'An operator needs to check the sign-in settings. Contact support with the reference below.', false],
        'email_unverified' => [403, 'Your email address is not verified', 'Verify your email address on InfoDot first, then sign in again.', true],
        'account_linked_elsewhere' => [403, 'This email already belongs to another sign-in', 'An account here with this email address is already connected to a different Dot identity, so it was not changed. Contact support with the reference below.', false],
        'not_provisioned' => [403, 'There is no account for you here yet', 'This platform does not create accounts automatically. Ask the person who runs it to invite you.', false],
        'provision_refused' => [403, 'We could not create your account here', 'The platform refused to create an account for you. Contact support with the reference below.', false],
        'claim_expired' => [400, 'That confirmation expired', 'Start again to receive a new code.', true],
        'claim_locked' => [429, 'Too many wrong codes', 'For your security this code is now cancelled. Start again to receive a new one.', true],
        'claim_missing' => [400, 'There is nothing to confirm', 'Start again from the sign-in.', true],
        'rate_limited' => [429, 'Too many tries', 'Wait a few minutes and start again.', true],
    ];

    public static function page(Request $request, string $code, array $context = []): Response
    {
        [$status, $headline, $body, $retry] = self::CATALOGUE[$code] ?? self::CATALOGUE['token_invalid'];

        Sso::log('error', ['reason' => $code, ...array_filter($context, fn ($v) => is_scalar($v) || $v === null)], 'warning');

        return response()
            ->view('dotsso::error', [
                'code' => $code,
                'headline' => $headline,
                'body' => $body,
                'retry' => $retry,
                'retryUrl' => route('dotsso.login'),
                'homeUrl' => url('/'),
            ], $status)
            ->header('Cache-Control', 'no-store');
    }
}
