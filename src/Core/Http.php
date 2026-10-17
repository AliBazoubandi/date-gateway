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

    public static function param(string $key, string $source = 'r'): ?string
    {
        $arr = $source === 'g' ? $_GET : ($source === 'p' ? $_POST : $_REQUEST);
        return isset($arr[$key]) ? (string)$arr[$key] : null;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }
}
