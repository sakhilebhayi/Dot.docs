<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate for the guardian observability endpoint. The report
 * exposes operational internals, so it is never public, and it fails
 * closed: no configured token means 503, not open access.
 */
final class AuthenticateGuardian
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('guardian.token');

        if ($configured === '') {
            return response()->json(['message' => 'Guardian endpoint is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $presented = $request->bearerToken();

        if (! is_string($presented) || ! hash_equals($configured, $presented)) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
