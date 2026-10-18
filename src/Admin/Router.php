<?php
declare(strict_types=1);
namespace Admin;

use Core\Csrf;
use Core\Http;
use Core\Session;

/**
 * admin URL map:
 *   GET  /admin                      dashboard
 *   GET/POST /admin/login, POST /admin/logout
 *   GET  /admin/connections, /admin/connections/new, /admin/connections/{id}
 *   POST /admin/connections (save), /admin/connections/delete
 *   GET  /admin/requests, requests/new, requests/{id}
 *   POST /admin/requests (save), requests/delete, requests/test
 *   GET  /admin/clients, clients/new, clients/{id}
 *   POST /admin/clients (save), clients/delete, clients/regen-key,
 *        clients/gen-secret, clients/permissions
 *   GET  /admin/logs, /admin/audit  (log + audit viewers with filters)
 */
final class Router
{
    public static function dispatch(string $path): void
    {
        Session::start();
        $parts = explode('/', trim($path, '/'));
        $page = strtolower($parts[1] ?? '');
        $sub = $parts[2] ?? '';
        // Numeric path segment wins; POST forms carry `id` in the body
        // (/admin/connections/delete etc.).
        $id = ctype_digit($sub) ? (int)$sub : (int)(Http::param('id', 'p') ?? 0);
        $action = ctype_digit($sub) || $sub === '' ? '' : strtolower($sub);

        if (Http::isPost()) {
            Csrf::check();
        }

        if ($page === 'login') {
            if (Http::isPost()) {
                Controllers\LoginController::post();
            } else {
                Controllers\LoginController::get();
            }
            return;
        }
        if ($page === 'logout') {
            // POST + CSRF only (verified above by Csrf::check() for every POST);
            // a plain GET must never log anyone out (link/image CSRF).
            if (Http::isPost()) {
                Auth::logout();
                Http::redirect('/admin/login');
            }
            Http::redirect('/admin');
        }

        // All other admin pages need a live session.
        Auth::require();

        switch ($page) {
            case '':
            case 'dashboard':
                Controllers\DashboardController::get();
                return;

            case 'connections':
                self::connections($sub, $action, $id);
                return;

            case 'requests':
                self::requests($sub, $action, $id);
                return;

            case 'clients':
                self::clients($sub, $action, $id);
                return;

            case 'logs':
                Controllers\LogController::index();
                return;

            case 'audit':
                Controllers\AuditController::index();
                return;

            default:
                self::notFound();
        }
    }

    private static function connections(string $sub, string $action, int $id): void
    {
        if (Http::isPost()) {
            if ($action === 'delete') {
                Controllers\ConnectionController::delete($id);
            } else {
                Controllers\ConnectionController::save($id);
            }
            return;
        }
        if ($sub === '' && $action === '') {
            Controllers\ConnectionController::list();
            return;
        }
        Controllers\ConnectionController::edit($id);
    }

    private static function requests(string $sub, string $action, int $id): void
    {
        if (Http::isPost()) {
            if ($action === 'delete') {
                Controllers\RequestController::delete($id);
            } elseif ($action === 'test') {
                Controllers\RequestController::testRun($id);
            } else {
                Controllers\RequestController::save($id);
            }
            return;
        }
        if ($sub === '' && $action === '') {
            Controllers\RequestController::list();
            return;
        }
        Controllers\RequestController::edit($id);
    }

    private static function clients(string $sub, string $action, int $id): void
    {
        if (Http::isPost()) {
            if ($action === 'delete') {
                Controllers\ClientController::delete($id);
            } elseif ($action === 'regen-key') {
                Controllers\ClientController::regenerateKey($id);
            } elseif ($action === 'gen-secret') {
                Controllers\ClientController::newSecret($id);
            } elseif ($action === 'permissions') {
                Controllers\ClientController::savePermissions($id);
            } else {
                Controllers\ClientController::save($id);
            }
            return;
        }
        if ($sub === '' && $action === '') {
            Controllers\ClientController::list();
            return;
        }
        Controllers\ClientController::edit($id);
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('404', [], '404');
    }
}
