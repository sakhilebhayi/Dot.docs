<?php

namespace Dot\SsoClient\Http\Controllers;

use Dot\SsoClient\Jwt\ProviderMetadata;
use Dot\SsoClient\Jwt\TokenVerifier;
use Dot\SsoClient\Support\ClaimService;
use Dot\SsoClient\Support\DeepLink;
use Dot\SsoClient\Support\Pending;
use Dot\SsoClient\Support\Resolver;
use Dot\SsoClient\Support\Sso;
use Dot\SsoClient\Support\SsoCompletion;
use Dot\SsoClient\Support\SsoError;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

/**
 * The sign-in leg of the central login. /sso/login starts it, /sso/callback ends it, /sso/logout is the "sign out of
 * every Dot platform" button (RP-initiated logout). Authorisation code + PKCE (S256) + state + nonce, nothing else.
 * No token, code, secret or verifier ever appears in a URL we build, a log line or a page.
 */
class SsoController extends Controller implements SsoCompletion
{
    /** The only values `screen` may carry. It selects which InfoDot page opens first and is never a URL. */
    private const SCREENS = ['register'];

    public function login(Request $request): RedirectResponse|Response
    {
        $screen = $request->query('screen');
        $screen = is_string($screen) && in_array($screen, self::SCREENS, true) ? $screen : null;

        // Already signed in here: no round trip, straight to where they were going.
        if (Sso::guard()->check()) {
            return redirect()->to($this->destination($request, $request->session()->get('url.intended')));
        }

        if (! config('dotsso.client_id') || ! config('dotsso.client_secret')) {
            return SsoError::page($request, 'misconfigured', ['detail' => 'client credentials missing']);
        }

        try {
            $endpoint = ProviderMetadata::endpoint('authorization_endpoint');
        } catch (\Throwable $e) {
            return SsoError::page($request, 'provider_unavailable', ['detail' => 'discovery failed']);
        }

        // The deep link is the platform's own server-side "intended" record (set when a guest hit a protected page).
        // It is validated now, kept only in the server-side state record, and validated again at the end.
        $deepLink = DeepLink::clean($request->session()->get('url.intended'), $request);

        $pending = Pending::start($request, $deepLink, $screen);

        $query = [
            'response_type' => 'code',
            'client_id' => (string) config('dotsso.client_id'),
            'redirect_uri' => Sso::redirectUri(),
            'scope' => (string) config('dotsso.scopes'),
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => $pending['challenge'],
            'code_challenge_method' => 'S256',
        ];

        if ($screen === 'register') {
            $query['prompt'] = 'create';
        }

        Sso::log('login_started', ['screen' => $screen, 'deep_link' => $deepLink !== null]);

        return redirect()->away($endpoint.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        $state = $request->query('state');

        if (! is_string($state) || $state === '' || strlen($state) > 200) {
            return SsoError::page($request, 'state_missing');
        }

        // The state is spent here, whatever happens next: a replayed callback finds nothing.
        [$record, $reason] = Pending::take($request, $state);

        if ($record === null) {
            return SsoError::page($request, $reason ?? 'state_unknown');
        }

        if ($request->query->has('error')) {
            $error = $request->query('error');

            return SsoError::page($request, 'denied', ['idp_error' => is_string($error) && preg_match('/^[a-z_]{1,40}$/', $error) ? $error : 'unrecognised']);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return SsoError::page($request, 'state_missing', ['detail' => 'no code']);
        }

        try {
            $response = Http::asForm()->acceptJson()
                ->timeout((int) config('dotsso.http_timeout_seconds', 10))
                ->post(ProviderMetadata::endpoint('token_endpoint'), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => Sso::redirectUri(),
                    'client_id' => (string) config('dotsso.client_id'),
                    'client_secret' => (string) config('dotsso.client_secret'),
                    'code_verifier' => $record['verifier'],
                ]);
        } catch (ConnectionException|\RuntimeException $e) {
            return SsoError::page($request, 'provider_unavailable', ['detail' => 'token endpoint unreachable']);
        }

        $idToken = $response->json('id_token');

        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            return SsoError::page($request, 'token_exchange_failed', ['status' => $response->status()]);
        }

        try {
            $claims = app(TokenVerifier::class)->verifyIdToken($idToken, (string) $record['nonce']);
        } catch (\RuntimeException $e) {
            // The messages are fixed strings from TokenVerifier, safe to log; the token itself never is.
            return SsoError::page($request, 'token_invalid', ['detail' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return SsoError::page($request, 'provider_unavailable', ['detail' => 'verification could not run']);
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        if (($claims['email_verified'] ?? false) !== true || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return SsoError::page($request, 'email_unverified', ['sub' => Sso::tag((string) $claims['sub'])]);
        }

        $claims = [
            'sub' => (string) $claims['sub'],
            'email' => $email,
            'name' => trim((string) ($claims['name'] ?? '')) ?: $email,
        ];

        $deepLink = DeepLink::clean($record['deep_link'] ?? null, $request);

        $outcome = Resolver::resolve($claims);

        if ($outcome['type'] === 'error') {
            return SsoError::page($request, $outcome['code'], ['sub' => Sso::tag($claims['sub'])]);
        }

        if ($outcome['type'] === 'claim') {
            return app(ClaimService::class)->begin($request, $outcome['user'], $claims, $deepLink, $idToken);
        }

        return $this->finish($request, $outcome['user'], $claims, $deepLink, $idToken, $outcome['how']);
    }

    /** @param array{sub: string, email: string, name: string} $claims */
    public function finish(Request $request, Authenticatable $user, array $claims, ?string $deepLink, string $idToken, string $how): RedirectResponse
    {
        Sso::guard()->login($user);
        $request->session()->regenerate();
        $request->session()->forget(['url.intended', 'dotsso.claim']);
        $request->session()->put('dotsso.session', [
            'sub' => $claims['sub'],
            'at_ms' => (int) floor(microtime(true) * 1000),
            'id_token' => $idToken,
        ]);

        Sso::provisioner()->afterSignIn($user, $claims);

        Sso::log('signed_in', ['how' => $how, 'sub' => Sso::tag($claims['sub']), 'deep_link' => $deepLink !== null]);

        return redirect()->to($deepLink ?? DeepLink::landing($request))->header('Cache-Control', 'no-store');
    }

    /** Sign out here AND at InfoDot (which then signs the person out of every platform by back-channel). */
    public function logout(Request $request): RedirectResponse
    {
        $hint = $request->session()->get('dotsso.session.id_token');

        Sso::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $after = Sso::postLogoutUri();

        try {
            if (! is_string($hint) || $hint === '') {
                return redirect()->to($after);
            }

            $endpoint = ProviderMetadata::endpoint('end_session_endpoint');
        } catch (\Throwable $e) {
            return redirect()->to($after);
        }

        $query = http_build_query([
            'id_token_hint' => $hint,
            'post_logout_redirect_uri' => $after,
            'state' => Pending::random(16),
        ], '', '&', PHP_QUERY_RFC3986);

        Sso::log('logout_started');

        return redirect()->away($endpoint.'?'.$query)->header('Cache-Control', 'no-store');
    }

    private function destination(Request $request, mixed $intended): string
    {
        return DeepLink::clean($intended, $request) ?? DeepLink::landing($request);
    }
}
