<?php
declare(strict_types=1);
namespace Api;

use Core\Config;
use Services\Encryption;

/** Absolute URLs for download links. */
final class Links
{
    public static function base(): string
    {
        $configured = (string)Config::get('app.base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
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
