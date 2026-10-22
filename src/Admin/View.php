<?php
declare(strict_types=1);
namespace Admin;

/**
 * Minimal view renderer: layout header/footer + a content template file.
 * Templates receive an array of variables and must escape via e().
 */
final class View
{
    /**
     * @param array<string,mixed> $vars
     * @param string $page  body[data-page] hook for per-page JS modules
     */
    public static function render(string $template, array $vars = [], string $title = '', string $page = ''): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            // Admin pages hold connection names, client secrets and result
            // counts; a shared browser or a proxy must never keep a copy that
            // outlives the session.
            header('Cache-Control: no-store');
            header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'");
            // Only meaningful over HTTPS: claiming it on a plain-HTTP dev or
            // proxy hop would pin the browser to TLS the host is not offering.
            $https = (string)($_SERVER['HTTPS'] ?? '');
            if ($https !== '' && strcasecmp($https, 'off') !== 0) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
        // No extract(): a caller var must never be able to overwrite this
        // method's own scope. Template variables are assigned explicitly by
        // name instead, and the names the renderer and the layout own are
        // pinned AFTER the loop, so a caller-provided 'title', 'csrf' or
        // 'admin' cannot forge session state or rename the template.
        $reserved = ['template' => true, 'content' => true, 'title' => true,
            'csrf' => true, 'admin' => true, 'page' => true, 'vars' => true, 'reserved' => true];
        foreach ($vars as $key => $value) {
            if (!is_string($key) || isset($reserved[$key])
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue; // only real, non-reserved PHP variable names pass
            }
            ${$key} = $value;
        }
        $title = $title !== '' ? $title : 'Admin';
        $csrf = \Core\Csrf::field();
        $admin = \Admin\Auth::current();
        ob_start();
        include __DIR__ . '/views/' . $template . '.php';
        $content = (string)ob_get_clean();
        ob_start();
        include __DIR__ . '/views/layout/header.php';
        echo $content;
        include __DIR__ . '/views/layout/footer.php';
        $html = (string)ob_get_clean();

        $base = \Core\Http::basePath();
        if ($base !== '') {
            $replaced = preg_replace_callback(
                '~\\b(href|action|src|data-pause-base)=(["\'])/(admin|assets|health)([/?"\'#]|$)~i',
                static fn(array $m): string => $m[1] . '=' . $m[2] . $base . '/' . $m[3] . $m[4],
                $html
            );
            if (is_string($replaced)) {
                $html = $replaced;
            }
        }
        echo $html;
    }

    /** Resolve a relative path with the base URL path prefix if configured. */
    public static function url(string $path): string
    {
        return \Core\Http::url($path);
    }

    /** Escape + format a MySQL datetime for display (short form). */
    public static function dt(?string $mysqlDt): string
    {
        if ($mysqlDt === null || $mysqlDt === '') {
            return '-';
        }
        return \Core\Str::e(substr($mysqlDt, 0, 16));
    }

    /**
     * Signed-out icon set? No: a single self-hosted SVG sprite
     * (public/assets/icons.svg, Lucide-style paths, stroke = currentColor).
     * Every icon is a <use> into that one cacheable file — no CDN, CSP-safe.
     *
     * @return string the <svg> markup, or '' for an unknown symbol
     */
    public static function icon(string $name, int $size = 16): string
    {
        static $known = [
            'grid', 'file', 'database', 'users', 'user', 'list', 'shield', 'search',
            'sun', 'moon', 'check', 'x', 'info', 'alert', 'clock', 'activity',
            'pulse', 'download', 'send', 'plus', 'filter', 'copy', 'logout',
            'zap', 'terminal', 'pause', 'play', 'chevron-down', 'chevron-start',
            'chevron-end', 'arrow-start', 'arrow-end', 'pin', 'key',
        ];
        if (!in_array($name, $known, true)) {
            return '';
        }
        return '<svg class="i" width="' . (int)$size . '" height="' . (int)$size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
            . ' focusable="false"><use href="' . \Core\Http::url('/assets/icons.svg#i-' . $name) . '"></use></svg>';
    }

    public static function badge(string $class, string $text): string
    {
        return '<span class="badge b-' . \Core\Str::e($class) . '">' . \Core\Str::e($text) . '</span>';
    }

    /** ' input-error' for a field with a validation error (red border in CSS). */
    public static function fcls(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' input-error' : '';
    }

    /** The inline error message of a field, or '' when the field is valid. */
    public static function ferr(array $errors, string $field): string
    {
        return isset($errors[$field])
            ? '<div class="field-error">' . \Core\Str::e((string)$errors[$field]) . '</div>'
            : '';
    }
}
