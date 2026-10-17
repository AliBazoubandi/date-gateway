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
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        // Byte-bounded so DB column limits still hold; strip a partial UTF-8
        // sequence from the cut point (invalid bytes only, <=3 per char).
        $cut = substr($s, 0, $limit);
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
