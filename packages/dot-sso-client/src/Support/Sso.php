<?php

namespace Dot\SsoClient\Support;

use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/** Small shared helpers: switches, urls, the guard, the provisioner and the one logging shape. */
class Sso
{
    public static function enabled(): bool
    {
        return (bool) config('dotsso.enabled');
    }

    public static function breakGlass(): bool
    {
        return (bool) config('dotsso.allow_local_login');
    }

    public static function guard(): Guard
    {
        return Auth::guard(config('dotsso.guard'));
    }

    public static function provisioner(): UserProvisioner
    {
        $class = config('dotsso.provisioner');

        if (! is_string($class) || ! is_a($class, UserProvisioner::class, true)) {
            throw new \LogicException('dotsso.provisioner must name a class implementing Dot\SsoClient\Contracts\UserProvisioner.');
        }

        return app($class);
    }

    public static function redirectUri(): string
    {
        return (string) (config('dotsso.redirect_uri') ?: url('/sso/callback'));
    }

    public static function postLogoutUri(): string
    {
        return (string) (config('dotsso.post_logout_redirect_uri') ?: url('/'));
    }

    /** https is required for the provider unless it is a local development host. */
    public static function issuerIsAcceptable(string $issuer): bool
    {
        $parts = parse_url($issuer);

        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (($parts['scheme'] ?? '') === 'https') {
            return true;
        }

        $host = strtolower($parts['host']);

        return ($parts['scheme'] ?? '') === 'http'
            && ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test'));
    }

    /**
     * One structured line per event, reason codes only. Never a token, code, secret, password or full email.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function log(string $event, array $context = [], string $level = 'info'): void
    {
        Log::log($level, 'dotsso.'.$event, $context);
    }

    /** A short, non-reversible tag for a value that must be correlated in logs without being printed. */
    public static function tag(string $value): string
    {
        return substr(hash_hmac('sha256', strtolower($value), (string) config('app.key')), 0, 10);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }
}
