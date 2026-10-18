<?php
declare(strict_types=1);
namespace Core;

/**
 * Storage paths + directory preparation.
 * storage/ holds result JSON files (storage/results/) and instant-cache copies
 * (storage/cache/). It lives OUTSIDE the web root when Apache serves public/.
 */
final class Filesystem
{
    private static ?string $base = null;

    public static function storageDir(): string
    {
        if (self::$base === null) {
            self::$base = Config::get('storage.dir')
                ?? BASE_PATH . '/' . 'storage';
        }
        return (string)self::$base;
    }

    public static function resultsDir(): string
    {
        return self::storageDir() . '/' . Config::get('storage.results_subdir', 'results');
    }

    public static function cacheDir(): string
    {
        return self::storageDir() . '/' . Config::get('storage.cache_subdir', 'cache');
    }

    /** Creates the storage directories if missing. */
    public static function prepare(): void
    {
        foreach ([self::storageDir(), self::resultsDir(), self::cacheDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create storage directory: {$dir}");
            }
        }
    }

    public static function absoluteFromRelative(string $relativeToStorage): string
    {
        return self::storageDir() . '/' . ltrim($relativeToStorage, '/\\');
    }

    /**
     * Resolve a STORED result/cache path (executions.result_path). The value
     * comes from the database, so it is untrusted input: only the documented
     * layout results|cache/name.json is accepted, and the resolved path must
     * stay inside storage/. Throws \RuntimeException on anything else —
     * callers treat that as "no usable file" (410/502), never as a path.
     */
    public static function resultPath(string $relative): string
    {
        if (preg_match('#^(results|cache)/[A-Za-z0-9._-]+\.json$#i', $relative) !== 1) {
            throw new \RuntimeException("Invalid stored result path: '{$relative}'");
        }
        $absolute = self::absoluteFromRelative($relative);
        $base = realpath(self::storageDir());
        $dir = realpath(dirname($absolute));
        if ($base === false || $dir === false
            || !str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', $base) . '/')) {
            throw new \RuntimeException("Stored result path escapes storage: '{$relative}'");
        }
        return $absolute;
    }

    public static function relative(string $absolute): string
    {
        return str_replace('\\', '/', substr($absolute, strlen(self::storageDir()) + 1));
    }
}
