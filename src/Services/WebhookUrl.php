<?php
declare(strict_types=1);
namespace Services;

use Core\Config;
use Core\IpRange;

/**
 * SSRF gate for webhook receiver URLs.
 *
 * A webhook URL is typed in by whoever holds an admin account, so without a
 * policy the gateway becomes a POST proxy into its own network:
 * http://169.254.169.254/... would fetch cloud instance credentials and hand
 * them to whoever reads the delivery, and http://127.0.0.1:<port> would let a
 * client poke at whatever the gateway host has listening.
 *
 * The rules run when a client is saved AND again before every send (a stored
 * URL can predate the policy, and DNS can answer differently between the two
 * checks) — the caller pins the address validated here with CURLOPT_RESOLVE so
 * the connection cannot be rebound after the fact.
 *
 * Defaults are the internal-deployment policy: https only, LAN receivers
 * allowed (webhook.allow_private_hosts is true because the receivers are
 * expected to sit on the private network), loopback refused, and link-local /
 * metadata / reserved ranges refused whatever the config says.
 */
final class WebhookUrl
{
    private const MAX_URL_LENGTH = 500;

    /** Never a valid receiver, regardless of configuration. */
    private const BLOCKED = [
        '0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', 'fe80::/10', 'ff00::/8', '2001:db8::/32',
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    /** RFC1918 + IPv6 ULA: allowed only while webhook.allow_private_hosts is on. */
    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /**
     * @param string $url a clients.webhook_url value
     * @return array{error:string,ip:string,resolve:list<string>} error is ''
     *         when the URL may be posted to, otherwise a message for the admin
     *         form and webhook_deliveries.last_error; ip is the accepted
     *         address; resolve holds CURLOPT_RESOLVE entries that pin the name
     *         to it (empty for a literal address, which cannot be rebound).
     */
    public static function check(string $url): array
    {
        $reject = static fn (string $why): array => ['error' => $why, 'ip' => '', 'resolve' => []];

        if (strlen($url) > self::MAX_URL_LENGTH) {
            return $reject('Webhook URL is too long.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return $reject('Webhook URL must be a full URL, e.g. https://hooks.example.test/gateway.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme === 'https') {
            $defaultPort = 443;
        } elseif ($scheme === 'http') {
            if (!self::flag('webhook.allow_http')) {
                return $reject('Webhook URL must use https. Set webhook.allow_http only for a receiver that cannot do TLS.');
            }
            $defaultPort = 80;
        } else {
            return $reject('Webhook URL must start with https:// (or http:// when webhook.allow_http is on).');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $reject('Webhook URL must not carry credentials.');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1); // IPv6 literal, as parse_url reports it
        }
        $host = rtrim($host, '.');
        if ($host === '') {
            return $reject('Webhook URL has no host name.');
        }

        $allowed = self::allowedHosts();
        if ($allowed !== [] && !in_array($host, $allowed, true)) {
            return $reject("Webhook URL host '{$host}' is not in the webhook.allowed_hosts list.");
        }

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($isLiteral) {
            $addresses = [$host];
        } else {
            // IPv4 only by design: gethostbynamel gives no AAAA records, so a
            // name that is only reachable over IPv6 is refused rather than
            // guessed at.
            $addresses = gethostbynamel($host) ?: [];
            if ($addresses === []) {
                return $reject("Webhook URL host '{$host}' does not resolve.");
            }
        }

        foreach ($addresses as $address) {
            $why = self::addressRejection((string)$address);
            if ($why !== '') {
                return $reject($why);
            }
        }

        $port = (int)($parts['port'] ?? $defaultPort);
        return [
            'error' => '',
            'ip' => (string)$addresses[0],
            'resolve' => $isLiteral
                ? []
                : ["{$host}:{$port}:{$addresses[0]}"],
        ];
    }

    /** '' when the address may receive a delivery, otherwise the reason. */
    private static function addressRejection(string $ip): string
    {
        if (IpRange::inRanges($ip, self::LOOPBACK)) {
            return self::flag('webhook.allow_loopback')
                ? ''
                : "Webhook URL points at a loopback address ({$ip}). Set webhook.allow_loopback for a receiver on this host.";
        }
        if (IpRange::inRanges($ip, self::BLOCKED)) {
            return "Webhook URL points at a blocked address ({$ip}) - link-local, metadata and reserved ranges are never reachable from the gateway.";
        }
        if (IpRange::inRanges($ip, self::PRIVATE)) {
            return self::flag('webhook.allow_private_hosts')
                ? ''
                : "Webhook URL points at a private-network address ({$ip}) while webhook.allow_private_hosts is off.";
        }
        return '';
    }

    /** @return list<string> lowercased, dot-trimmed host names; [] = no list */
    private static function allowedHosts(): array
    {
        $hosts = [];
        foreach ((array)Config::get('webhook.allowed_hosts', []) as $host) {
            $host = rtrim(strtolower(trim((string)$host)), '.');
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        return $hosts;
    }

    private static function flag(string $key): bool
    {
        return (bool)Config::get($key, false);
    }
}
