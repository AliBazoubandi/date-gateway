<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Admin\View;
use Core\Http;
use Core\Session;

final class LoginController
{
    public static function get(): void
    {
        if (Auth::current() !== null) {
            Http::redirect('/admin');
        }
        Session::start();
        $error = (string)($_SESSION['login_error'] ?? '');
        unset($_SESSION['login_error']);
        if ($error === '' && (string)($_GET['expired'] ?? '') === '1') {
            $error = 'Your session expired. Please log in again.';
        }
        View::render('login', ['error' => $error, 'username' => (string)($_SESSION['login_username'] ?? '')], 'Login');
        unset($_SESSION['login_username']);
    }

    public static function post(): void
    {
        Session::start();
        $username = trim((string)Http::param('username', 'p'));
        $password = (string)Http::param('password', 'p');
        $_SESSION['login_username'] = $username;
        $error = Auth::login($username, $password);
        if ($error !== null) {
            $_SESSION['login_error'] = $error;
            // tiny pace-down against brute force
            usleep(300000);
        }
        Http::redirect('/admin/login');
    }
}
