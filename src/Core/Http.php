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

    /**
     * Subpath prefix under which the gateway is mounted (e.g. '/gateway').
     * Returns empty string '' when mounted at the domain root (e.g. http://gw.internal).
     */
    public static function basePath(): string
    {
        // 1. Explicit environment / config override
        $explicit = (string)Config::get('app.base_path', '');
        if ($explicit !== '') {
            return '/' . trim($explicit, '/');
        }

        // 2. Derive from app.base_url (e.g. 'https://rbb.palood.ir/gateway')
        $baseUrl = (string)Config::get('app.base_url', '');
        if ($baseUrl !== '') {
            $path = parse_url($baseUrl, PHP_URL_PATH);
            if (is_string($path)) {
                $trimmed = trim($path, '/');
                if ($trimmed !== '') {
                    return '/' . $trimmed;
                }
            }
        }

        // 3. Reverse proxy header if passed (e.g. X-Forwarded-Prefix: /gateway)
        $forwardedPrefix = (string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '');
        if ($forwardedPrefix !== '') {
            $trimmed = trim($forwardedPrefix, '/');
            if ($trimmed !== '') {
                return '/' . $trimmed;
            }
        }

        // 4. Auto-detect from SCRIPT_NAME if served under an Apache Alias or subfolder
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (str_ends_with(strtolower($script), '/index.php')) {
            $dir = str_replace('\\', '/', dirname($script));
            if ($dir !== '' && $dir !== '/' && $dir !== '.') {
                return '/' . trim($dir, '/');
            }
        }

        return '';
    }

    /**
     * Resolves a root-relative path (e.g. '/admin') into a path prefixed with basePath()
     * (e.g. '/gateway/admin') if mounted under a subfolder.
     */
    public static function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = self::basePath();
        if ($base === '') {
            return $path;
        }
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        if ($path === $base || str_starts_with($path, $base . '/') || str_starts_with($path, $base . '?')) {
            return $path;
        }
        return $base . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    public static function path(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $base = self::basePath();
        if ($base !== '') {
            if ($path === $base) {
                return '/';
            }
            if (str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base));
                if ($path === '' || !str_starts_with($path, '/')) {
                    $path = '/' . ltrim($path, '/');
                }
            }
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
        $target = self::url($path);
        header('Location: ' . $target, true, 302);
        exit;
    }

    public static function rawBody(): string
    {
        return (string)file_get_contents('php://input');
    }
}

