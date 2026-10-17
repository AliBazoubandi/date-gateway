<?php
declare(strict_types=1);
namespace Core;

/**
 * Minimal .env support: config/.env (KEY=VALUE lines, # comments) is loaded
 * once via putenv(); config/config.php reads values with Env::get().
 * Real secrets live ONLY in config/.env, which is gitignored. Variables
 * already present in the real environment take precedence over the file.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $envFile): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (!is_file($envFile)) {
            return;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $len = strlen($value);
            if ($len >= 2
                && (($value[0] === '"' && $value[$len - 1] === '"')
                    || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    /** Raw string value or the default; type conversion belongs to the caller. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }
}
