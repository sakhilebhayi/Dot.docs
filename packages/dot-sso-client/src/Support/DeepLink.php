<?php

namespace Dot\SsoClient\Support;

use Illuminate\Http\Request;

/**
 * The only place that decides whether a "where was the person going" value may be used. A deep link is never read
 * from the sign-in request or the callback: it comes from the platform's own server-side intended-URL record, is
 * checked here when the sign-in starts and again when it ends, and is kept inside the server-side state record.
 *
 * Accepted: a path on THIS platform (a leading single slash, or an absolute URL whose scheme and host are exactly this
 * request's), matching the platform's allow patterns and none of the deny patterns (login, register, sso and so on, so
 * no loop is possible). Anything else returns null and the caller uses the platform's safe landing path.
 */
class DeepLink
{
    public static function clean(mixed $raw, Request $request, bool $checkAllow = true): ?string
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > 2048) {
            return null;
        }

        if (preg_match('/[\x00-\x1f\x7f\s]/', $raw)) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $raw)) {
            $parts = parse_url($raw);

            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
                || strtolower($parts['scheme'] ?? '') !== $request->getScheme()
                || strtolower($parts['host'] ?? '') !== strtolower($request->getHost())
                || (isset($parts['port']) && (int) $parts['port'] !== (int) $request->getPort())) {
                return null;
            }

            $raw = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        // Fragments never reach the server and add nothing here.
        $raw = explode('#', $raw, 2)[0];

        if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//') || str_contains($raw, '\\')) {
            return null;
        }

        // The same checks on the decoded forms, so %2f%2f, %5c and %2e%2e cannot smuggle what the raw check refused.
        $decoded = $raw;

        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($decoded);

            if (preg_match('/[\x00-\x1f\x7f]/', $decoded)
                || str_starts_with($decoded, '//') || str_contains($decoded, '\\')
                || preg_match('#(^|/)\.\.(/|\?|$)#', $decoded)
                || (isset($decoded[0]) && $decoded[0] !== '/')) {
                return null;
            }
        }

        foreach ((array) config('dotsso.deep_link.deny', []) as $pattern) {
            if (preg_match($pattern, $raw) || preg_match($pattern, $decoded)) {
                return null;
            }
        }

        if (! $checkAllow) {
            return $raw;
        }

        foreach ((array) config('dotsso.deep_link.allow', []) as $pattern) {
            if (preg_match($pattern, $raw)) {
                return $raw;
            }
        }

        return null;
    }

    /** The platform's own landing path when no deep link is usable (and a last check of it). */
    public static function landing(Request $request): string
    {
        // The landing path is the platform's own choice: it is held to the shape and deny rules, not to the deep-link allowlist.
        return self::clean((string) config('dotsso.landing', '/'), $request, checkAllow: false) ?? '/';
    }
}
