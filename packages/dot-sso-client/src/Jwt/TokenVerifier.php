<?php

namespace Dot\SsoClient\Jwt;

use Illuminate\Support\Facades\Cache;

/**
 * Validates the JWTs the identity provider signs: id_tokens at sign-in and logout tokens on
 * back-channel logout. Verification is deliberately strict (R-20 §8): RS256 only — an unsigned or
 * HMAC-alg token is rejected outright, which also closes the classic key-confusion swap — and
 * issuer, audience, timestamps and (for id_tokens) the nonce must all match before any claim is
 * trusted.
 */
class TokenVerifier
{
    /** @return array<string, mixed> the verified claims */
    public function verifyIdToken(string $jwt, string $expectedNonce): array
    {
        $claims = $this->verifySignatureAndBasics($jwt);

        if ($expectedNonce === '' || ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new \RuntimeException('id_token nonce mismatch.');
        }

        if (isset($claims['azp']) && $claims['azp'] !== (string) config('dotsso.client_id')) {
            throw new \RuntimeException('id_token azp mismatch.');
        }

        if (! isset($claims['sub']) || ! is_string($claims['sub']) || $claims['sub'] === '' || strlen($claims['sub']) > 64) {
            throw new \RuntimeException('id_token has no usable subject.');
        }

        // Lifetimes: a token for this exchange was minted moments ago and does not promise an absurd lifetime.
        if (! isset($claims['iat']) || ! is_numeric($claims['iat'])) {
            throw new \RuntimeException('id_token has no issue time.');
        }

        if ($claims['iat'] < time() - (int) config('dotsso.id_token_max_age_seconds', 600) - (int) config('dotsso.leeway_seconds')) {
            throw new \RuntimeException('id_token is too old for this exchange.');
        }

        if ((int) config('dotsso.id_token_max_lifetime_seconds', 86400) < $claims['exp'] - $claims['iat']) {
            throw new \RuntimeException('id_token lifetime is longer than allowed.');
        }

        return $claims;
    }

    /** @return array<string, mixed> the verified claims (OIDC Back-Channel Logout 1.0 §2.6 checks) */
    public function verifyLogoutToken(string $jwt): array
    {
        $claims = $this->verifySignatureAndBasics($jwt, requireExp: false);

        if (isset($claims['exp']) && time() > $claims['exp'] + (int) config('dotsso.leeway_seconds')) {
            throw new \RuntimeException('Logout token is expired.');
        }

        $events = $claims['events'] ?? [];

        if (! array_key_exists('http://schemas.openid.net/event/backchannel-logout', (array) $events)) {
            throw new \RuntimeException('Not a logout token (events claim missing).');
        }

        if (isset($claims['nonce'])) {
            throw new \RuntimeException('A logout token must not carry a nonce.');
        }

        if (! isset($claims['sub']) || $claims['sub'] === '') {
            throw new \RuntimeException('Logout token has no subject.');
        }

        $leeway = (int) config('dotsso.leeway_seconds');

        if (! isset($claims['iat']) || $claims['iat'] < time() - 300 - $leeway) {
            throw new \RuntimeException('Logout token is too old.');
        }

        // jti single-use: a replayed logout token is dropped.
        if (! isset($claims['jti']) || ! Cache::add('dotsso:logout-jti:'.$claims['jti'], 1, 600)) {
            throw new \RuntimeException('Logout token replay detected.');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private function verifySignatureAndBasics(string $jwt, bool $requireExp = true): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->b64url($encodedHeader), true);

        if (! is_array($header)) {
            throw new \RuntimeException('Malformed token header.');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new \RuntimeException('Token algorithm is not RS256.');
        }

        $pem = ProviderMetadata::signingKey($header['kid'] ?? null);

        $verified = openssl_verify(
            $encodedHeader.'.'.$encodedPayload,
            $this->b64url($encodedSignature),
            $pem,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new \RuntimeException('Token signature verification failed.');
        }

        $claims = json_decode($this->b64url($encodedPayload), true);

        if (! is_array($claims)) {
            throw new \RuntimeException('Malformed token payload.');
        }

        $leeway = (int) config('dotsso.leeway_seconds');

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim((string) config('dotsso.issuer'), '/')) {
            throw new \RuntimeException('Token issuer mismatch.');
        }

        $audience = (array) ($claims['aud'] ?? []);

        if (! config('dotsso.client_id') || ! in_array((string) config('dotsso.client_id'), array_map('strval', $audience), true)) {
            throw new \RuntimeException('Token audience mismatch.');
        }

        if ($requireExp && (! isset($claims['exp']) || time() > $claims['exp'] + $leeway)) {
            throw new \RuntimeException('Token is expired.');
        }

        if (isset($claims['iat']) && $claims['iat'] > time() + $leeway) {
            throw new \RuntimeException('Token issued in the future.');
        }

        return $claims;
    }

    private function b64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
