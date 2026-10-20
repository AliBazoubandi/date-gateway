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

    /**
     * Canonical text form of an address, with IPv4-mapped IPv6 collapsed to
     * its embedded IPv4 address: `::ffff:127.0.0.1` (and `::ffff:7f00:1`,
     * the same bytes) becomes `127.0.0.1`.
     *
     * Why this exists: the policy tables below are v4 and v6 ranges, and
     * inCidr() is deliberately family-strict (a 16-byte address never matches
     * a 4-byte range). But Windows and Linux both route `::ffff:a.b.c.d` to
     * the IPv4 address it embeds, so without this step a loopback or metadata
     * endpoint written in mapped form slipped past every such check.
     *
     * Returns null when the input is not an IP address at all: callers treat
     * that as "cannot classify", which for an SSRF gate means reject.
     */
    public static function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            return null;
        }
        // ::ffff:0:0/96 - 10 zero bytes, then two 0xFF bytes, then the v4 address.
        if (strlen($bytes) === 16
            && substr($bytes, 0, 10) === str_repeat("\x00", 10)
            && $bytes[10] === "\xFF"
            && $bytes[11] === "\xFF") {
            $v4 = @inet_ntop(substr($bytes, 12));
            return $v4 === false ? null : $v4;
        }
        $text = @inet_ntop($bytes);
        return $text === false ? null : $text;
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
