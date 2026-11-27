<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Dot\SsoClient\Support\Sso;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * R2-8: while central sign-in is on, Dot.docs' local sign-in pages are closed to every kind of request - page, form,
 * script or API client alike - and each refusal is logged. Signing in to Dot.docs is InfoDot's job then, with its MFA,
 * lockout and sign-out. Defence in depth beside the shared dot-sso-client package's own redirect; it honours the
 * package's break-glass switch and `allow_paths`. See .ai/rules/app.md.
 */
class RefuseLocalSignInWhileSso
{
    private const PAGES = ['login', 'register', 'forgot-password', 'reset-password', 'reset-password/*', 'two-factor-challenge'];

    public function handle(Request $request, Closure $next): Response
    {
        // It guards Dot.docs' own accounts: the package's own test suite runs inside this app with its TestUser model,
        // and tests the package as shipped.
        if (config('auth.providers.users.model') !== User::class) {
            return $next($request);
        }

        if (! Sso::enabled() || Sso::breakGlass() || ! $request->is(...self::PAGES)
            || $request->is(...(array) config('dotsso.allow_paths', []))) {
            return $next($request);
        }

        Sso::log('local_auth_refused_app_guard', ['path' => $request->path(), 'method' => $request->method()], 'warning');

        return redirect()->to(route('dotsso.login'), $request->isMethod('GET') || $request->isMethod('HEAD') ? 302 : 303);
    }
}
