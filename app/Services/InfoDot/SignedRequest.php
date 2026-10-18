<?php

namespace App\Services\InfoDot;

use Illuminate\Http\Request;

/**
 * Is this server to server request really from InfoDot, and fresh?
 *
 * The signature is HMAC-SHA256 over "{timestamp}.{raw body}" with this app's INFODOT_SERVICE_SECRET
 * (the secret it already redeems handoff tokens with), so only InfoDot can make it, a body cannot be
 * swapped under a signature, and a captured request goes stale in five minutes.
 */
class SignedRequest
{
    private const TOLERANCE = 300;

    public static function valid(Request $request): bool
    {
        $secret = (string) config('ecosystem.infodot.secret');
        $timestamp = (int) $request->header('X-InfoDot-Timestamp');
        $signature = (string) $request->header('X-InfoDot-Signature');

        if ($secret === '' || $timestamp === 0 || abs(time() - $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
    }
}
