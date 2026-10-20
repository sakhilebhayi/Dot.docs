<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EcosystemAuthController extends Controller
{
    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is still understood for
     * links minted before this change, and is spent exactly the same way.
     */
    public function handle(Request $request): RedirectResponse
    {
        if ($request->isMethod('post') && ! $this->postedFromInfoDot($request)) {
            return $this->refused();
        }

        try {
            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (HttpExceptionInterface $e) {
            return $this->refused($e->getStatusCode() === 503);
        }
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function postedFromInfoDot(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
        }

        $host = strtolower($host);

        // When this app knows InfoDot's address, exactly that origin is accepted, not a sibling subdomain.
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return $host === strtolower($infodot);
        }

        // An app without that setting falls back to its own parent domain.
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        return $host === $self || $host === $parent || str_ends_with($host, '.'.$parent);
    }

    private function refused(bool $unavailable = false): RedirectResponse
    {
        return redirect('/login', 303)->with('status', $unavailable
            ? 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.'
            : 'We could not sign you in through InfoDot. Please open this app again from InfoDot.');
    }

    public function redeem(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $token = $request->input('token');

        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $user = $identity ? $provision($identity) : null;

            if ($user !== null) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        Auth::login($user);

        return redirect()->to($this->safeRedirect($request->input('redirect')) ?? route('dashboard'));
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        /** @var User|null $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user;
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
