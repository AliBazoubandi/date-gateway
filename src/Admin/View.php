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
        // $title/$csrf/$admin are pinned by the explicit writes below, but
        // $template (include path) and $content are read after extract() —
        // caller vars must never be able to rename the template or inject
        // output, so strip those keys before extraction.
        unset($vars['template'], $vars['content']);
        $vars['title'] = $title !== '' ? $title : 'Admin';
        $vars['csrf'] = \Core\Csrf::field();
        $vars['admin'] = \Admin\Auth::current();
        extract($vars, EXTR_OVERWRITE);
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

    public static function badge(string $class, string $text): string
    {
        return '<span class="badge b-' . \Core\Str::e($class) . '">' . \Core\Str::e($text) . '</span>';
    }
}
