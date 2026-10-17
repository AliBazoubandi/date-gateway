<?php
declare(strict_types=1);
namespace Api;

use Api\Errors;
use Core\Db;
use Core\Str;

/**
 * Bearer-key authentication. Keys are 32+ random bytes, shown once at creation;
 * only SHA-256 hashes are stored, looked up via the unique index.
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
        $hash = hash('sha256', $key);
        return \Core\Db::instance()->one(
            'SELECT id, name, type, is_active, webhook_url, webhook_secret FROM clients WHERE api_key_hash = ?',
            [$hash]
        );
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
