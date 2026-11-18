<?php

namespace Dot\SsoClient\Http\Middleware;

use Closure;
use Dot\SsoClient\Support\Sso;
use Illuminate\Http\Request;

/**
 * While DOTSSO_ENABLED is false the package's routes do not exist as far as anyone can tell. One exception, named as
 * `claim` on the claim routes: a claim already in this visitor's session (started by the platform's fallback
 * receiver while the flag is off) can be finished, because refusing it would leave the person with nothing.
 */
class RequireEnabled
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $claimInProgress = $mode === 'claim' && $request->hasSession() && is_array($request->session()->get('dotsso.claim'));

        abort_unless(Sso::enabled() || $claimInProgress, 404);

        return $next($request);
    }
}
