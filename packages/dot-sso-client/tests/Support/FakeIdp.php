<?php

namespace Dot\SsoClient\Tests\Support;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * An identity provider in a box: discovery, JWKS and a token endpoint that, like InfoDot's, checks the PKCE verifier
 * against the challenge it saw at authorize time, the redirect URI and the client secret. It signs real RS256 tokens
 * with a real key, so the package's verification is exercised for real.
 */
class FakeIdp
{
    public const ISSUER = 'https://idp.test';

    public const CLIENT_ID = 'client-123';

    public const SECRET = 'secret-xyz';

    public const REDIRECT = 'https://platform.test/sso/callback';

    /** @var array<string, array{challenge: string, nonce: string, claims: array<string, mixed>}> */
    public array $codes = [];

    /** @var list<array<string, mixed>> */
    public array $tokenRequests = [];

    public int $tokenStatus = 200;

    /** Override of the claims put into the next id_token (merged over the defaults). @var array<string, mixed> */
    public array $claimOverrides = [];

    /** When set, the id_token is signed with this key instead of the real one. */
    public mixed $signWith = null;

    public ?string $forceAlg = null;

    /** When set, the token endpoint answers with exactly this array (HTTP 200). @var array<string, mixed>|null */
    public ?array $tokenBody = null;

    private static mixed $key = null;

    private static mixed $otherKey = null;

    public string $kid = 'idp-1';

    public bool $down = false;

    /** Replacements for parts of the discovery document. @var array<string, mixed> */
    public array $discoveryPatch = [];

    /** The keys the JWKS endpoint serves (null = the real key under $kid). @var list<array<string, string>>|null */
    public ?array $jwks = null;

    public static function key(): mixed
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public static function otherKey(): mixed
    {
        return self::$otherKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public function install(): static
    {
        config([
            'dotsso.enabled' => true,
            'dotsso.issuer' => self::ISSUER,
            'dotsso.client_id' => self::CLIENT_ID,
            'dotsso.client_secret' => self::SECRET,
            'dotsso.redirect_uri' => self::REDIRECT,
            'dotsso.post_logout_redirect_uri' => 'https://platform.test/',
            'dotsso.provisioner' => TestProvisioner::class,
            'dotsso.auto_provision' => true,
        ]);

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => fn () => $this->down ? Http::response('', 503) : Http::response(array_merge($this->discovery(), $this->discoveryPatch)),
            self::ISSUER.'/oauth/jwks' => fn () => Http::response(['keys' => $this->jwks ?? [$this->jwk()]]),
            self::ISSUER.'/oauth/token' => fn (HttpRequest $r) => $this->token($r),
        ]);

        return $this;
    }

    /** @return array<string, mixed> */
    public function discovery(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'jwks_uri' => self::ISSUER.'/oauth/jwks',
            'end_session_endpoint' => self::ISSUER.'/oauth/end-session',
        ];
    }

    /** @return array<string, string> */
    public function jwk(?string $kid = null, mixed $key = null): array
    {
        $d = openssl_pkey_get_details($key ?? self::key());

        return ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid ?? $this->kid,
            'n' => self::b64($d['rsa']['n']), 'e' => self::b64($d['rsa']['e'])];
    }

    /** Parse the Location the package redirected to. @return array<string, string> */
    public static function authorizeParams(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        return $q;
    }

    /** What the provider does at /oauth/authorize once the person has signed in: issue a code bound to this request. */
    public function approve(array $authorize, array $claims = []): string
    {
        $code = 'code-'.bin2hex(random_bytes(6));
        $this->codes[$code] = [
            'challenge' => $authorize['code_challenge'],
            'nonce' => $authorize['nonce'],
            'claims' => $claims,
        ];

        return $code;
    }

    /** @return array<string, mixed> */
    public function defaultClaims(array $extra = []): array
    {
        $now = time();

        return array_merge([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600,
            'sub' => 'uuid-1111', 'email' => 'ada@example.test', 'email_verified' => true, 'name' => 'Ada Lovelace',
        ], $extra);
    }

    public function mint(array $claims, ?string $alg = null, mixed $key = null): string
    {
        $alg ??= $this->forceAlg ?? 'RS256';
        $header = ['alg' => $alg, 'typ' => 'JWT', 'kid' => $this->kid];
        $input = self::b64(json_encode($header)).'.'.self::b64(json_encode($claims));

        if ($alg === 'none') {
            return $input.'.';
        }

        if ($alg === 'HS256') {
            // The classic key-confusion attempt: an HMAC signed with the public key as the secret.
            $pem = openssl_pkey_get_details(self::key())['key'];

            return $input.'.'.self::b64(hash_hmac('sha256', $input, $pem, true));
        }

        openssl_sign($input, $sig, $key ?? $this->signWith ?? self::key(), OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($sig);
    }

    private function token(HttpRequest $request): mixed
    {
        $d = $request->data();
        $this->tokenRequests[] = $d;

        if ($this->tokenBody !== null) {
            return Http::response($this->tokenBody);
        }

        if ($this->tokenStatus !== 200) {
            return Http::response(['error' => 'server_error'], $this->tokenStatus);
        }

        $entry = $this->codes[$d['code'] ?? ''] ?? null;

        if ($entry === null
            || ($d['client_id'] ?? null) !== self::CLIENT_ID
            || ($d['client_secret'] ?? null) !== self::SECRET
            || ($d['redirect_uri'] ?? null) !== self::REDIRECT
            || ($d['grant_type'] ?? null) !== 'authorization_code'
            || self::b64(hash('sha256', (string) ($d['code_verifier'] ?? ''), true)) !== $entry['challenge']) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        // Single use, like the real provider.
        unset($this->codes[$d['code']]);

        $claims = $this->defaultClaims(array_merge(['nonce' => $entry['nonce']], $entry['claims'], $this->claimOverrides));
        $claims = array_filter($claims, fn ($v) => $v !== '__unset__');

        return Http::response(['token_type' => 'Bearer', 'expires_in' => 900, 'access_token' => 'at-secret', 'refresh_token' => 'rt-secret', 'id_token' => $this->mint($claims)]);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
