<?php
declare(strict_types=1);
namespace Services;

use Core\Config;
use Core\Filesystem;

/**
 * Free-space floor for NEW result files (limits.min_free_disk_bytes).
 *
 * Checked before a writer opens, not mid-stream: the per-result byte cap
 * already bounds one run, but a volume that is nearly full when the run starts
 * would fail the write halfway through and leave the client with a crashed
 * execution instead of an honest refusal.
 */
final class StorageGuard
{
    /** True when a new result file may be written right now. */
    public static function hasRoom(): bool
    {
        return self::reason() === '';
    }

    /** '' when there is room, otherwise the reason to record for the failure. */
    public static function reason(): string
    {
        $min = (int)Config::get('limits.min_free_disk_bytes', 0);
        if ($min <= 0) {
            return '';
        }
        $free = Filesystem::freeBytes();
        if ($free < 0) {
            return ''; // unknown free space: the size caps still apply
        }
        return $free >= $min ? '' : self::format($free) . ' free, ' . self::format($min) . ' required';
    }

    private static function format(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GiB';
        }
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' MiB'
            : $bytes . ' B';
    }
}
