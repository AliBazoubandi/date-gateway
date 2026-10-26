<?php
declare(strict_types=1);
namespace Core;

/**
 * Per-session CSRF token, required on every admin POST form.
 */
final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = Str::hex(32);
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . Str::e(self::token()) . '">';
    }

    public static function check(): void
    {
        Session::start();
        $sent = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $have = (string)($_SESSION['csrf'] ?? '');
        if ($sent === '' || $have === '' || !hash_equals($have, $sent)) {
            http_response_code(419);
            echo '<h1>419 - invalid session token</h1><p>Form expired. Go back and retry.</p>';
            exit;
        }
    }
}
