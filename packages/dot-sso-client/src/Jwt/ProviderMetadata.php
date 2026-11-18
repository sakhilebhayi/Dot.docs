<?php

namespace Dot\SsoClient\Jwt;

use Dot\SsoClient\Support\Sso;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The identity provider's published OIDC metadata and signing keys, resolved from the configured
 * issuer (never from a request parameter) and cached. Endpoints always come from discovery, so a
 * platform configures exactly one value: the issuer it trusts.
 */
class ProviderMetadata
{
    /** @return array<string, mixed> */
    public static function discovery(): array
    {
        $issuer = (string) config('dotsso.issuer');

        if (! Sso::issuerIsAcceptable($issuer)) {
            throw new \RuntimeException('The configured issuer must be an https URL (http only for localhost, 127.0.0.1 and *.test).');
        }

        return Cache::remember('dotsso:discovery:'.md5($issuer), config('dotsso.jwks_cache_seconds'), function () use ($issuer) {
            $doc = Http::timeout((int) config('dotsso.http_timeout_seconds', 10))->get($issuer.'/.well-known/openid-configuration')->throw()->json();

            if (rtrim($doc['issuer'] ?? '', '/') !== $issuer) {
                throw new \RuntimeException('Identity provider discovery document does not match the configured issuer.');
            }

            return $doc;
        });
    }

    public static function endpoint(string $name): string
    {
        $url = static::discovery()[$name] ?? null;

        if (! is_string($url) || ! str_starts_with($url, rtrim((string) config('dotsso.issuer'), '/').'/')) {
            throw new \RuntimeException("Identity provider metadata is missing a usable {$name}.");
        }

        return $url;
    }

    /**
     * The provider's RSA public key as PEM, selected by kid when the token names one.
     * JWKS carries public material only; nothing secret is fetched or stored.
     */
    public static function signingKey(?string $kid): string
    {
        $match = fn (array $keys) => collect($keys)->first(
            fn ($key) => ($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256'
                && ($kid === null || ($key['kid'] ?? null) === $kid)
        );

        $cacheKey = 'dotsso:jwks:'.md5((string) config('dotsso.issuer'));
        $fetch = fn () => Http::timeout((int) config('dotsso.http_timeout_seconds', 10))->get(static::endpoint('jwks_uri'))->throw()->json('keys') ?? [];

        $key = $match(Cache::remember($cacheKey, config('dotsso.jwks_cache_seconds'), $fetch));

        // A rotated key: refresh once when the named kid is unknown, then give up.
        if ($key === null && $kid !== null && Cache::add($cacheKey.':refresh', 1, 60)) {
            $keys = $fetch();
            Cache::put($cacheKey, $keys, config('dotsso.jwks_cache_seconds'));
            $key = $match($keys);
        }

        if ($key === null || ! isset($key['n'], $key['e'])) {
            throw new \RuntimeException('No matching signing key in the identity provider JWKS.');
        }

        return static::rsaPem($key['n'], $key['e']);
    }

    /** Assemble a SubjectPublicKeyInfo PEM from the JWK's modulus and exponent. */
    private static function rsaPem(string $n, string $e): string
    {
        $modulus = static::derInteger(static::b64url($n));
        $exponent = static::derInteger(static::b64url($e));

        $rsaKey = static::derSequence($modulus.$exponent);
        $bitString = chr(0x03).static::derLength(strlen($rsaKey) + 1).chr(0x00).$rsaKey;
        $algorithm = hex2bin('300d06092a864886f70d0101010500'); // rsaEncryption, NULL params
        $spki = static::derSequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private static function b64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private static function derInteger(string $bytes): string
    {
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = chr(0x00).$bytes; // keep the integer positive
        }

        return chr(0x02).static::derLength(strlen($bytes)).$bytes;
    }

    private static function derSequence(string $bytes): string
    {
        return chr(0x30).static::derLength(strlen($bytes)).$bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
