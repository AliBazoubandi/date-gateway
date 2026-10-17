<?php
declare(strict_types=1);
namespace Admin;

use Core\Session;

/** One-shot flash messages kept in the admin session. */
final class Flashes
{
    public static function set(string $type, string $message): void
    {
        Session::start();
        $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * @return array<int,array{type:string,message:string}>
     */
    public static function take(): array
    {
        Session::start();
        $f = $_SESSION['flashes'] ?? [];
        unset($_SESSION['flashes']);
        return $f;
    }
}
