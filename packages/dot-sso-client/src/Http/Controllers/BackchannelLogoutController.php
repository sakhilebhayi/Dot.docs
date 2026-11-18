<?php

namespace Dot\SsoClient\Http\Controllers;

use Dot\SsoClient\Jwt\TokenVerifier;
use Dot\SsoClient\Support\Sso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * OIDC Back-Channel Logout 1.0, receiving side. InfoDot posts a signed logout token server to server (no browser, no
 * session, no CSRF cookie: its authenticity is the signature). A valid token ends every local session of that identity:
 * the subject is recorded (EnforceSsoRevocation closes any still-open session on its next request, whatever the session
 * driver) and, when sessions live in the database, the rows are deleted at once.
 */
class BackchannelLogoutController extends Controller
{
    public function __invoke(Request $request, TokenVerifier $verifier): JsonResponse
    {
        $token = $request->input('logout_token');

        if (! is_string($token) || $token === '' || strlen($token) > 8192) {
            Sso::log('backchannel_rejected', ['reason' => 'no_token'], 'warning');

            return $this->no('invalid_request');
        }

        try {
            $claims = $verifier->verifyLogoutToken($token);
        } catch (\RuntimeException $e) {
            Sso::log('backchannel_rejected', ['reason' => $e->getMessage()], 'warning');

            return $this->no('invalid_request');
        } catch (\Throwable $e) {
            Sso::log('backchannel_rejected', ['reason' => 'verification_unavailable'], 'error');

            return $this->no('server_error', 503);
        }

        $sub = (string) $claims['sub'];

        DB::table('dotsso_revocations')->insert([
            'sub' => $sub,
            'revoked_at_ms' => (int) floor(microtime(true) * 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Housekeeping: a revocation older than any session could last is never needed again.
        DB::table('dotsso_revocations')->where('created_at', '<', now()->subDays(3))->delete();

        $ended = 0;

        if (config('session.driver') === 'database') {
            try {
                $user = Sso::provisioner()->findByUuid($sub);

                if ($user) {
                    $ended = DB::table(config('session.table', 'sessions'))->where('user_id', $user->getAuthIdentifier())->delete();
                }
            } catch (\Throwable $e) {
                Sso::log('backchannel_session_sweep_failed', ['error' => class_basename($e)], 'warning');
            }
        }

        Sso::log('backchannel_logout', ['sub' => Sso::tag($sub), 'sessions_deleted' => $ended]);

        return response()->json([], 200, ['Cache-Control' => 'no-store']);
    }

    private function no(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error], $status, ['Cache-Control' => 'no-store']);
    }
}
