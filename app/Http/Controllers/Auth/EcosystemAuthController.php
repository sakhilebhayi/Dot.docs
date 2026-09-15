<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $accessToken = PersonalAccessToken::findToken($request->query('token'));

        abort_if(
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast()),
            403
        );

        /** @var User $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        Auth::login($user);

        return redirect()->to($this->safeRedirect($request->query('redirect')) ?? route('dashboard'));
    }

    /**
     * Where a handoff may land: a path INSIDE this application, or nowhere.
     *
     * The other products in the ecosystem link a person straight to the page
     * they were heading for ("open this document in Dot.Doc"), so the target
     * arrives as a query parameter - and a query parameter that becomes a
     * redirect is an open redirect unless it is pinned to this origin. A
     * value is accepted only when it starts with a single `/`, carries no
     * backslash (browsers normalise `\` to `/`, so `/\evil.test` is a
     * protocol-relative URL in disguise), and has no `:` before the first
     * `/` after the leading one, which is what keeps a scheme out. Anything
     * else falls back to the dashboard rather than erroring: a bad link
     * should still sign the person in.
     */
    private function safeRedirect(mixed $target): ?string
    {
        if (! is_string($target) || $target === '') {
            return null;
        }

        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        if (str_contains($target, '\\') || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return null;
        }

        $rest = substr($target, 1);
        $firstSlash = strpos($rest, '/');
        $head = $firstSlash === false ? $rest : substr($rest, 0, $firstSlash);

        if (str_contains($head, ':')) {
            return null;
        }

        return $target;
    }
}
