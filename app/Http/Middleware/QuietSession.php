<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route whose requests should not write the session back when they changed nothing in it. The skipping
 * itself is App\Http\Middleware\StartSession's: this only says the route may. For endpoints called constantly and
 * read-only as far as the session goes - the editor's sync poll.
 */
class QuietSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(StartSession::QUIET, true);

        return $next($request);
    }
}
