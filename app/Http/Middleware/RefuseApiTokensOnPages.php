<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * API tokens are for /api only (R2-1 / S-07).
 *
 * The signed-in pages use `auth:sanctum`, which accepts a bearer token as well as a session. Without this, a token
 * made for reading through the API also opened every page and every Livewire action, exports, deletes and shares
 * included, with every power its owner has. It sits on the whole web group (bootstrap/app.php), so a request that
 * carries a Dot.docs API token never reaches a page; a browser session is unaffected.
 */
class RefuseApiTokensOnPages
{
    public function handle(Request $request, Closure $next): Response
    {
        // On every web route, Livewire's /livewire/update included: Livewire re-runs the page's auth:sanctum for a
        // component action, and Sanctum falls back to a bearer token when there is no session. Only a Dot.docs API
        // token is refused - another bearer credential (the Guardian report's) is not this middleware's business.
        $bearer = $request->bearerToken();

        if ($bearer !== null && PersonalAccessToken::findToken($bearer) !== null) {
            abort(403, 'API tokens work on the API (/api) only.');
        }

        return $next($request);
    }

    /**
     * The other half, for routes/api.php: only a token gets in. A session that reached the API some other way
     * (a future stateful-API setting, a test's actingAs) is refused, so a token's permissions are always what decides.
     */
    public static function tokenOnly(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null || $request->user() === null) {
            abort(401, 'The API needs a token.');
        }

        return $next($request);
    }
}
