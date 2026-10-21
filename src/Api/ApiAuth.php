<?php
declare(strict_types=1);
namespace Api;

use Api\Errors;
use Core\ApiKeyHash;
use Core\Db;
use Core\Str;

/**
 * Bearer-key authentication. Keys are 32+ random bytes, shown once at creation;
 * only hashes are stored. Lookups try the peppered form first
 * (api_key_hash_hmac = HMAC-SHA256 keyed by security.master_key) and fall back
 * to the legacy bare SHA-256 (api_key_hash) so keys stored before the pepper
 * keep working; a legacy hit upgrades the row in place (Core\ApiKeyHash).
 */
final class ApiAuth
{
    /** @return array<string,mixed>|null the client row, or null */
    public static function clientFromBearer(): ?array
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    $header = (string)$value;
                    break;
                }
            }
        }
        if (preg_match('/^\s*bearer\s+(\S+)\s*$/i', $header, $m) !== 1) {
            return null;
        }
        $key = $m[1];
        if (strlen($key) < 40 || strlen($key) > 128) {
            return null;
        }
        $sha = ApiKeyHash::legacy($key);
        $hmac = ApiKeyHash::peppered($key);
        // Both hash columns are unique and hold only hex, so one indexed
        // lookup answers both the peppered-first and the legacy path.
        $client = Db::instance()->one(
            'SELECT id, name, type, is_active, webhook_url, api_key_hash, api_key_hash_hmac
             FROM clients WHERE api_key_hash_hmac = ? OR api_key_hash = ?',
            [$hmac, $sha]
        );
        if ($client !== null && !hash_equals($hmac, (string)($client['api_key_hash_hmac'] ?? ''))) {
            // Legacy-only hit: store the peppered hash so future lookups stop
            // depending on the bare SHA-256. The legacy hash stays for
            // master-key-rotation recovery (see Core\ApiKeyHash); the write is
            // idempotent and race-safe (the value is a pure function of the
            // key and the pepper).
            Db::instance()->execute(
                'UPDATE clients SET api_key_hash_hmac = ? WHERE id = ?',
                [$hmac, (int)$client['id']]
            );
        }
        if ($client !== null) {
            unset($client['api_key_hash'], $client['api_key_hash_hmac']);
        }
        return $client;
    }

    public static function requireClient(): array
    {
        $client = self::clientFromBearer();
        if ($client === null) {
            Errors::unauthorized();
        }
        if ((int)$client['is_active'] !== 1) {
            Errors::forbidden('This client is deactivated.');
        }
        return $client;
    }
}
