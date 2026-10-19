<?php
declare(strict_types=1);
namespace Core;

/**
 * IPv4/IPv6 address-range math, shared by the webhook SSRF gate
 * (Services\WebhookUrl) and the admin trusted-proxy list.
 *
 * Deliberately not filter_var's PRIV/RES flags: what each range means here is a
 * policy decision of the gateway, so the tables live in one place and are
 * readable.
 */
final class IpRange
{
    /**
     * True when $ip is inside $cidr (e.g. '10.0.0.0/8'). A bare address compares
     * as /32 (or /128). Mismatched families (v6 address, v4 range) never match.
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = self::splitCidr($cidr);
        $ipBytes = @inet_pton(trim($ip));
        $netBytes = @inet_pton(trim($network));
        if ($ipBytes === false || $netBytes === false || strlen($ipBytes) !== strlen($netBytes)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBytes, 0, $bytes) !== substr($netBytes, 0, $bytes)) {
            return false;
        }
        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;
        return (ord($ipBytes[$bytes]) & $mask) === (ord($netBytes[$bytes]) & $mask);
    }

    /** @param list<string> $ranges @return bool true when $ip falls in any range */
    public static function inRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inCidr($ip, (string)$range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a comma-separated list of addresses/CIDRs (the way an operator
     * writes them in config/.env): trimmed, lowercased, empty entries dropped.
     *
     * @return list<string>
     */
    public static function parseList(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @return array{0:string,1:int} [network address, prefix length] */
    private static function splitCidr(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            return [$cidr, strlen(@inet_pton(trim($cidr)) ?: '') === 16 ? 128 : 32];
        }
        [$network, $bits] = explode('/', $cidr, 2);
        $prefix = max(0, min(128, (int)$bits));
        // A v4 range written without a prefix must not swallow a v6 address.
        if (strlen(@inet_pton(trim($network)) ?: '') === 4) {
            $prefix = min($prefix, 32);
        }
        return [$network, $prefix];
    }
}
