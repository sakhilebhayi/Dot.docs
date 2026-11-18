<?php

namespace App\Support;

/**
 * Where the "Sign in" and "Create account" entry points of the signed-out pages go. With DOTSSO_ENABLED they go straight
 * to the central sign-in (no hop through the local pages); otherwise they behave exactly as before. The interception in
 * the shared package would catch /login and /register anyway, so this only saves the extra redirect.
 */
class CentralSignIn
{
    public static function active(): bool
    {
        return (bool) config('dotsso.enabled') && ! (bool) config('dotsso.allow_local_login');
    }

    public static function login(): string
    {
        return self::active() ? route('dotsso.login') : route('login');
    }

    /** @param  string  $fallback  where "Create account" goes when the central sign-in is off */
    public static function register(string $fallback): string
    {
        return self::active() ? route('dotsso.login', ['screen' => 'register']) : $fallback;
    }
}
