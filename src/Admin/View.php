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
     */
    public static function render(string $template, array $vars = [], string $title = ''): void
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
            'csrf' => true, 'admin' => true, 'vars' => true, 'reserved' => true];
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
        include __DIR__ . '/views/layout/header.php';
        echo $content;
        include __DIR__ . '/views/layout/footer.php';
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
     * Inline SVG icon set (feather-style, stroke = currentColor, no external
     * assets — the CSP allows nothing else). Purely presentational.
     *
     * @return string the <svg> markup, or '' for an unknown name
     */
    public static function icon(string $name, int $size = 16): string
    {
        static $icons = [
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="10" y2="13"/><line x1="16" y1="17" x2="10" y2="17"/><polyline points="10 9 9 9 8 9"/>',
            'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'list' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'check' => '<polyline points="20 6 9 17 4 12"/>',
            'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'copy' => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        ];
        $body = $icons[$name] ?? '';
        if ($body === '') {
            return '';
        }
        return '<svg class="i" width="' . (int)$size . '" height="' . (int)$size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
            . ' focusable="false">' . $body . '</svg>';
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
