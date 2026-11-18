<?php

namespace Dot\SsoClient\Support;

use Illuminate\Http\Request;

/**
 * The server-side record of a sign-in in progress: state, nonce, the PKCE verifier and the validated deep link live in
 * the platform's own session store, keyed by the state value. The browser only ever carries the opaque `state` (and the
 * ordinary session cookie); the verifier, nonce and deep link are never in a URL. Each record is single use and expires.
 */
class Pending
{
    private const KEY = 'dotsso.pending';

    /** @return array{state: string, nonce: string, verifier: string, challenge: string} */
    public static function start(Request $request, ?string $deepLink, ?string $screen): array
    {
        $state = self::random(32);
        $nonce = self::random(32);
        $verifier = self::random(64);

        $records = (array) $request->session()->get(self::KEY, []);
        $records[$state] = [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'deep_link' => $deepLink,
            'screen' => $screen,
            'created' => now()->getTimestamp(),
        ];

        // Several tabs may sign in at once; keep a few, drop the oldest.
        uasort($records, fn ($a, $b) => $a['created'] <=> $b['created']);
        $records = array_slice($records, -max(1, (int) config('dotsso.max_pending', 5)), null, true);
        $request->session()->put(self::KEY, $records);

        return [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
    }

    /**
     * Take (and delete) the record for a state value. Returns [record|null, reason|null]; a state is spent whatever
     * the outcome, so a replayed callback finds nothing.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public static function take(Request $request, string $state): array
    {
        $records = (array) $request->session()->get(self::KEY, []);

        if ($state === '' || ! isset($records[$state])) {
            return [null, 'state_unknown'];
        }

        $record = $records[$state];
        unset($records[$state]);
        $request->session()->put(self::KEY, $records);

        if (now()->getTimestamp() - (int) $record['created'] > (int) config('dotsso.state_ttl_seconds', 600)) {
            return [null, 'state_expired'];
        }

        return [$record, null];
    }

    public static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
