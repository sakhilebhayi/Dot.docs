<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only a personal access token reaches the token API (R2-1 / S-07); see RefuseApiTokensOnPages::tokenOnly(). */
class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        return RefuseApiTokensOnPages::tokenOnly($request, $next);
    }
}
