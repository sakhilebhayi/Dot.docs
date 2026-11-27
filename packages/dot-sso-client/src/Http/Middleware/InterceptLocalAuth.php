<?php

namespace Dot\SsoClient\Http\Middleware;

use Closure;
use Dot\SsoClient\Support\Sso;
use Illuminate\Http\Request;

/**
 * While SSO is on, the platform's own sign-in doors stop being doors: GET /login, /register, /forgot-password and
 * /reset-password* go to /sso/login (register with the fixed value screen=register, never a URL). The deep link is not
 * carried in the address: it is the platform's own server-side "intended" record, read when /sso/login starts.
 *
 * Credential POSTs to the same endpoints are refused too, so the old form cannot be used by posting to it directly.
 *
 * Never touched: machine paths (API, Sanctum, webhooks, broadcasting auth, health checks), signed URLs, the platform's
 * `allow_paths`, and every path outside the fixed list. Machine access is decided by PATH only: an Accept header or a
 * bearer token is chosen by the caller, so neither may turn a sign-in page into a machine endpoint.
 * DOTSSO_ALLOW_LOCAL_LOGIN is the administrator's break-glass: it switches the interception off while it is set.
 */
class InterceptLocalAuth
{
    private const PAGES = ['login', 'register', 'forgot-password', 'reset-password', 'reset-password/*'];

    private const MACHINE = ['api/*', 'sanctum/*', 'webhooks/*', 'webhook/*', 'broadcasting/*', 'up', 'up/*', 'ready', 'guardian/*', 'csp-report', 'sso/*'];

    public function handle(Request $request, Closure $next)
    {
        if (! Sso::enabled() || Sso::breakGlass() || $this->machine($request)) {
            return $next($request);
        }

        $isPage = $request->is(...self::PAGES);

        if (! $isPage || $request->is(...(array) config('dotsso.allow_paths', []))) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $to = route('dotsso.login', $request->is('register') ? ['screen' => 'register'] : []);

            Sso::log('local_auth_redirected', ['path' => $request->is('register') ? 'register' : 'other']);

            return redirect()->to($to)->header('Cache-Control', 'no-store');
        }

        if ($request->isMethod('POST') && config('dotsso.block_credential_posts', true)) {
            Sso::log('local_auth_post_refused', ['path' => $request->path()], 'warning');

            return redirect()->to(route('dotsso.login'), 303);
        }

        return $next($request);
    }

    private function machine(Request $request): bool
    {
        if ($request->is(...self::MACHINE)) {
            return true;
        }

        return $request->query('signature') !== null && $request->hasValidSignature();
    }
}
