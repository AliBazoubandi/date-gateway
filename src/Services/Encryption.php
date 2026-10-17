<?php
declare(strict_types=1);
namespace Services;

use Core\Config;
use Core\Str;

/**
 * AES-256-GCM encryption for connection passwords, keyed by the master key
 * in config/config.php (kept outside the web root).
 *
 * Blob format: gcm:v1:<iv_b64>:<tag_b64>:<ct_b64>
 */
final class Encryption
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key === null) {
            $hex = (string)Config::get('security.master_key', '');
            if (!Str::isSha256Hex($hex)) {
                throw new \RuntimeException(
                    'security.master_key is not set to 64 hex chars. Generate one with: ' .
                    'php -r "echo bin2hex(random_bytes(32));"'
                );
            }
            if (strspn($hex, '0') === 64) {
                throw new \RuntimeException(
                    'security.master_key is still the placeholder of all zeros. ' .
                    'Generate a real key: php -r "echo bin2hex(random_bytes(32));"'
                );
            }
            self::$key = hash('sha256', hex2bin($hex), true);
        }
        return self::$key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed (openssl).');
        }
        return 'gcm:v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ct);
    }

    public static function decrypt(string $blob): string
    {
        $parts = explode(':', $blob);
        if (count($parts) !== 5 || $parts[0] !== 'gcm' || $parts[1] !== 'v1') {
            throw new \RuntimeException('Stored connection password has an unknown format.');
        }
        $iv = base64_decode($parts[2], true);
        $tag = base64_decode($parts[3], true);
        $ct = base64_decode($parts[4], true);
        if ($iv === false || $tag === false || $ct === false || strlen($iv) !== 12) {
            throw new \RuntimeException('Stored connection password is corrupted.');
        }
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException(
                'Decryption failed. The security.master_key does not match the one used to store this password.'
            );
        }
        return $plain;
    }
}
