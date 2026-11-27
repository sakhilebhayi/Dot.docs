<?php

namespace App\Http\Middleware;

use App\Audit\AuditLogger;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API call made with a token is written to the audit log (R2-1 / S-07): which token, which route, the answer.
 *
 * Written after the response, so a refused call (403 for a missing permission, 409 for a stale write) is logged too.
 * The token's name is kept with the row, so the log still reads after the token is deleted.
 */
class LogApiUse
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $this->audit->record('api.request', $token, [
                'token' => $token->name,
                'method' => $request->method(),
                'route' => $request->route()?->getName() ?? $request->path(),
                'status' => $response->getStatusCode(),
            ], $user);
        }

        return $response;
    }
}
