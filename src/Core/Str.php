<?php
declare(strict_types=1);
namespace Core;

final class Str
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function json($value, int $flags = 0): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE guarantees a string even when data
        // contains malformed UTF-8 (mismatched driver charset): json_encode
        // would otherwise return false and any `: string` caller would blow
        // up with an uncaught TypeError (500 + stuck 'running' executions).
        $json = json_encode(
            $value,
            $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        // Substitution does not cover every failure: INF/NAN, a resource or a
        // recursive object still return false, which would break the same
        // `: string` contract. An empty object is the least-surprising answer
        // for a value that has no JSON representation at all.
        return is_string($json) ? $json : '{}';
    }

    // ---- Input validators (path params must pass these) ---------------------

    public static function isCode(string $s): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $s) === 1;
    }

    public static function isCallId(string $s): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $s) === 1;
    }

    public static function isToken(string $s): bool
    {
        return preg_match('/^[0-9a-f]{48}$/', $s) === 1;
    }

    public static function isSha256Hex(string $s): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $s) === 1;
    }

    public static function truncate(string $s, int $limit = 500): string
    {
        if (strlen($s) <= $limit) {
            return $s;
        }
        // Byte-bounded so DB column limits still hold; reserve 3 bytes for the
        // '…' suffix and strip a partial UTF-8 sequence from the cut point
        // (invalid bytes only, <=3 per char).
        $cut = substr($s, 0, max(0, $limit - 3));
        while ($cut !== '' && preg_match('//u', $cut) === false) {
            $cut = substr($cut, 0, -1);
        }
        return $cut . '…';
    }

    public static function sanitizeEvent(string $e): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]/i', '', substr($e, 0, 31)) ?? '');
    }
}
