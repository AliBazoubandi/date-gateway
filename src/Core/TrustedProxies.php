<?php
declare(strict_types=1);
namespace Core;

/**
 * Who the gateway is willing to believe about the real client address.
 *
 * Behind a TLS-terminating proxy every request arrives from the proxy, so
 * REMOTE_ADDR is the same address for all admins: the login throttle would
 * lock everybody out after 3 bad passwords from one person, and the API logs
 * would record the proxy instead of the caller. X-Forwarded-For is the fix,
 * but it is attacker-controlled unless the peer really is a proxy we named —
 * so the list must be filled in deliberately (admin.trusted_proxies) and the
 * address taken is the RIGHTMOST entry the list does not explain, which is the
 * last hop a trusted proxy could not have been spoofed into writing.
 *
 * With an empty list (the default) nothing is trusted and client_ip() keeps
 * returning REMOTE_ADDR.
 */
final class TrustedProxies
{
    /** @return list<string> configured addresses/CIDRs, lowercased */
    public static function configured(): array
    {
        $list = [];
        foreach ((array)Config::get('admin.trusted_proxies', []) as $entry) {
            $entry = strtolower(trim((string)$entry));
            if ($entry !== '') {
                $list[] = $entry;
            }
        }
        return $list;
    }

    /**
     * The address to record and throttle on.
     *
     * @param string $remoteAddr $_SERVER['REMOTE_ADDR']
     * @param string $header     $_SERVER['HTTP_X_FORWARDED_FOR'] as received
     * @param list<string> $trusted
     */
    public static function resolve(string $remoteAddr, string $header, array $trusted): string
    {
        if ($remoteAddr === '' || $trusted === [] || !self::isTrusted($remoteAddr, $trusted)) {
            return $remoteAddr; // peer is not one of ours: the header is noise
        }
        $hops = self::hops($header);
        if ($hops === null) {
            return $remoteAddr;
        }
        foreach (array_reverse($hops) as $hop) {
            if (!self::isTrusted($hop, $trusted)) {
                return $hop;
            }
        }
        // Nothing left to believe (empty or fully-internal header): the peer.
        return $remoteAddr;
    }

    /**
     * X-Forwarded-For as written, left to right. null when the header holds
     * anything that is not a bare address (a port suffix, an obfuscated or
     * forged token): a header someone has been creative with is not evidence,
     * and the caller falls back to the peer address.
     *
     * @return list<string>|null
     */
    public static function hops(string $header): ?array
    {
        if (trim($header) === '') {
            return []; // no header at all is not a suspicious one
        }
        $hops = [];
        foreach (explode(',', $header) as $item) {
            $item = strtolower(trim($item));
            if (str_starts_with($item, '[') && str_ends_with($item, ']')) {
                $item = substr($item, 1, -1); // bracketed IPv6
            }
            if (filter_var($item, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            $hops[] = $item;
        }
        return $hops;
    }

    /** @param list<string> $trusted */
    public static function isTrusted(string $ip, array $trusted): bool
    {
        return $ip !== '' && IpRange::inRanges($ip, $trusted);
    }
}
