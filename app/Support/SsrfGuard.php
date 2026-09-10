<?php

namespace App\Support;

/**
 * Ported from Dot.Forms (app/Support/SsrfGuard.php). The same check guards
 * outbound webhook deliveries in both products, so the two should not drift —
 * with one deliberate difference this copy carries and Dot.Forms does not yet:
 * the CGNAT block (100.64.0.0/10) is rejected as well, because filter_var()
 * counts it as neither private nor reserved and some hosts run internal
 * services and metadata endpoints there. Port it back when convenient.
 *
 * The guard vets a URL, not a conversation: it cannot see where a target
 * redirects to. Callers must therefore not follow redirects — see
 * App\Services\WebhookService::fire(), which sends withoutRedirecting().
 */
class SsrfGuard
{
    /** Carrier-grade NAT — RFC 6598. Not covered by filter_var()'s flags. */
    private const CGNAT_CIDR = ['100.64.0.0', 10];

    /**
     * True only if $url is http(s), has a resolvable host, and every IP that
     * hostname resolves to is a public, routable address — no loopback,
     * private (RFC1918), link-local (including the 169.254.169.254 cloud
     * metadata endpoint), or other reserved range. Checked again immediately
     * before each outbound request, not only when the URL was saved, since
     * DNS can change between the two (DNS rebinding).
     */
    public static function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        if (strtolower($host) === 'localhost') {
            return false;
        }

        // parse_url() keeps the brackets on an IPv6 literal; filter_var()
        // will not validate them, so strip them before the IP check.
        $host = trim($host, '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : self::resolveAll($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }

            if (self::isCgnat($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 100.64.0.0/10. FILTER_FLAG_NO_PRIV_RANGE covers only RFC1918 and
     * NO_RES_RANGE only 0/8, 127/8, 169.254/16 and 240/4, so this range —
     * shared address space that a host may put internal services on — falls
     * through both and has to be checked by hand.
     */
    private static function isCgnat(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        [$base, $bits] = self::CGNAT_CIDR;

        $mask = -1 << (32 - $bits);

        return (ip2long($ip) & $mask) === (ip2long($base) & $mask);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveAll(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
