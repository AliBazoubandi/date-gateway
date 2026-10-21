<?php
declare(strict_types=1);
namespace Core;

final class Http
{
    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function path(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public static function segment(int $n): string
    {
        $parts = explode('/', ltrim(self::path(), '/'));
        return isset($parts[$n]) ? rawurldecode($parts[$n]) : '';
    }

    /**
     * Read one request parameter. $source must be 'g' ($_GET) or 'p' ($_POST);
     * anything else throws instead of falling back to $_REQUEST — a silent
     * REQUEST fallback would let a query string feed a POST-only field (and
     * reach the CSRF token), which is exactly what the per-source split exists
     * to prevent.
     */
    public static function param(string $key, string $source = 'g'): ?string
    {
        $arr = match ($source) {
            'g' => $_GET,
            'p' => $_POST,
            default => throw new \InvalidArgumentException(
                "Http::param source must be 'g' (GET) or 'p' (POST), got '{$source}'"
            ),
        };
        return isset($arr[$key]) ? (string)$arr[$key] : null;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }
}
