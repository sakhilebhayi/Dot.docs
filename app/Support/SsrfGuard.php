<?php

namespace App\Support;

/**
 * Ported from Dot.Forms (app/Support/SsrfGuard.php), unchanged in substance:
 * the same check guards outbound webhook deliveries in both products, so the
 * two should not drift.
 */
class SsrfGuard
{
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
        }

        return true;
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
