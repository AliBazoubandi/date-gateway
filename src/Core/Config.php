<?php
declare(strict_types=1);
namespace Core;

final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    public static function load(string $file): void
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException('config/config.php must return an array.');
        }
        self::$data = $data;
    }

    /**
     * Dotted-path getter: 'app.timezone', 'limits.instant.max_rows', ...
     */
    public static function get(string $path, $default = null)
    {
        $node = self::$data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data ?? [];
    }
}
