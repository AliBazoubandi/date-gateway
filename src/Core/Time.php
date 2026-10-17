<?php
declare(strict_types=1);
namespace Core;

final class Time
{
    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone('Asia/Tehran');
    }

    /** Current time as a MySQL DATETIME string in Tehran local time. */
    public static function now(): string
    {
        return self::mysql(new \DateTimeImmutable('now'));
    }

    public static function mysql(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s');
    }

    public static function nowDt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    public static function fromSql(string $s): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($s, self::timezone());
        } catch (\Exception) {
            return null;
        }
    }

    public static function addMinutes(\DateTimeImmutable $dt, int $minutes): \DateTimeImmutable
    {
        return $dt->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes');
    }

    public static function addSeconds(\DateTimeImmutable $dt, int $seconds): \DateTimeImmutable
    {
        return $dt->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }

    /**
     * ISO 8601 with Tehran offset, e.g. "2026-10-17T14:30:15+03:30".
     * Accepts a MySQL "Y-m-d H:i:s" string, a DateTimeInterface, or null/empty.
     */
    public static function iso($t = null): ?string
    {
        if ($t === null || $t === '') {
            return null;
        }
        if ($t instanceof \DateTimeInterface) {
            return $t->format('c');
        }
        if (is_numeric($t)) {
            return null;
        }
        $dt = self::fromSql((string)$t);
        return $dt ? $dt->format('c') : null;
    }
}
