<?php
declare(strict_types=1);
namespace Api;

use Core\Config;
use Services\Encryption;

/** Absolute URLs for download links. */
final class Links
{
    /**
     * Base URL for absolute links. Strict on purpose — links end up in
     * client-facing payloads, so a bad or missing configuration fails fast
     * instead of silently producing unusable (or Host-header-spoofed) URLs:
     *
     *  - GATEWAY_BASE_URL must be a valid http(s) URL; https is required
     *    unless app.debug is on (development machines may use plain http).
     *  - Empty in a CLI process: throws — there is no Host header to fall
     *    back on and cli/check.php flags the misconfiguration as a FAIL.
     *  - Empty in a web process: falls back to the incoming Host header
     *    ONLY when app.debug is on; in production it throws, because
     *    building security-relevant links from a client-controlled header
     *    is a cache-poisoning/phishing risk.
     */
    public static function base(): string
    {
        $configured = trim((string)Config::get('app.base_url', ''));
        if ($configured !== '') {
            if (filter_var($configured, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException("app.base_url is not a valid URL: '{$configured}'");
            }
            $scheme = strtolower((string)parse_url($configured, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \RuntimeException("app.base_url must use the http or https scheme: '{$configured}'");
            }
            if ($scheme !== 'https' && !self::debug()) {
                throw new \RuntimeException("app.base_url must use https:// in production: '{$configured}'");
            }
            return rtrim($configured, '/');
        }
        if (PHP_SAPI === 'cli') {
            throw new \RuntimeException('app.base_url is not configured; set GATEWAY_BASE_URL in config/.env');
        }
        if (self::debug()) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            return $scheme . '://' . $host . \Core\Http::basePath();
        }
        throw new \RuntimeException(
            'app.base_url is not configured; refusing to build links from the Host header in production. '
            . 'Set GATEWAY_BASE_URL in config/.env'
        );
    }

    private static function debug(): bool
    {
        return (bool)Config::get('app.debug', false);
    }

    public static function download(string $token): string
    {
        return self::base() . '/api/v1/results/' . $token;
    }

    /**
     * Stable per-call download link, rebuilt from the AES-256-GCM copy of the
     * call's token (calls.token_enc). NULL for calls without one (instant
     * mode, rows predating the column, or an unreadable blob after a master
     * key change) — callers then simply omit the link.
     */
    public static function forCall(string $callId): ?string
    {
        $enc = \Core\Db::instance()->value('SELECT token_enc FROM calls WHERE id = ?', [$callId]);
        if ($enc === null || (string)$enc === '') {
            return null;
        }
        try {
            return self::download(Encryption::decrypt((string)$enc));
        } catch (\RuntimeException) {
            return null;
        }
    }
}
