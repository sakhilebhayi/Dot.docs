<?php

namespace Dot\SsoClient\Http\Middleware;

use Closure;
use Dot\SsoClient\Support\Sso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A session that was opened through SSO remembers who it is for and when it began. If InfoDot has since told us (back
 * channel) that this identity signed out, the session is ended on its next request, whatever the session driver.
 * Sessions that were not opened through SSO (break-glass local login, machine tokens) are never touched.
 */
class EnforceSsoRevocation
{
    public function handle(Request $request, Closure $next)
    {
        if (! Sso::enabled() || ! $request->hasSession()) {
            return $next($request);
        }

        $sso = $request->session()->get('dotsso.session');

        if (is_array($sso) && isset($sso['sub'], $sso['at_ms'])) {
            $revoked = DB::table('dotsso_revocations')
                ->where('sub', $sso['sub'])
                ->where('revoked_at_ms', '>', (int) $sso['at_ms'])
                ->exists();

            if ($revoked) {
                Sso::guard()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                Sso::log('session_ended_by_backchannel', ['sub' => Sso::tag((string) $sso['sub'])]);

                return $request->expectsJson()
                    ? response()->json(['message' => 'Signed out.'], 401)
                    : redirect()->to(url('/'));
            }
        }

        return $next($request);
    }
}
