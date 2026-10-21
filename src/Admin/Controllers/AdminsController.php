<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Admin\Flashes;
use Admin\View;
use Core\Db;
use Core\Http;
use Core\Str;
use Services\AuditLogger;

/**
 * Admin Users page: list admin accounts, create one (FormAuth-style password
 * hashing identical to cli\create_admin.php), enable / disable. Creating an
 * account and toggling it are audited; deactivation bumps session_version so
 * any live browser of that account is signed out at its next request
 * (Auth::current() checks both is_active and session_version).
 */
final class AdminsController
{
    public static function list(): void
    {
        $rows = Db::instance()->rows(
            'SELECT id, username, is_active, last_login_at, failed_attempts,
                    locked_until, session_version, created_at
             FROM admin_users
             ORDER BY is_active DESC, username'
        );
        $me = Auth::require();
        View::render('admins/index', [
            'rows' => $rows,
            'me'   => (int)$me['id'],
        ], 'Admin Users', 'admins');
    }

    public static function create(): void
    {
        if (!Http::isPost()) {
            Http::redirect('/admin/admins');
        }
        $db = Db::instance();
        $me = Auth::require();

        $username = trim((string)Http::param('username', 'p'));
        $password = (string)Http::param('password', 'p');

        $errors = [];
        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
            $errors['username'] = 'Use 3-64 characters: letters, digits, dot, dash, underscore.';
        }
        if (strlen($password) < 10) {
            $errors['password'] = 'Password must be at least 10 characters.';
        }
        if ($errors === []
            && $db->value('SELECT COUNT(*) FROM admin_users WHERE username = ?', [$username]) > 0) {
            $errors['username'] = 'That username already exists.';
        }
        if ($errors !== []) {
            Flashes::set('error', reset($errors));
            Http::redirect('/admin/admins');
        }

        $db->execute(
            'INSERT INTO admin_users (username, password_hash, is_active, created_at)
             VALUES (?, ?, 1, ?)',
            [$username, Auth::hash($password), now_str()]
        );
        $id = (int)$db->insertId();
        unset($password);

        // 'via' stays 'admin_panel' in the after-snapshot: the CLI records the
        // same action with 'via' => 'cli', so the audit trail can tell both apart.
        AuditLogger::record('admin', 'create', $id, $username, null,
            ['username' => $username, 'via' => 'admin_panel'], (int)$me['id']);
        Flashes::set('ok', "Admin user «{$username}» added.");
        Http::redirect('/admin/admins');
    }

    public static function toggle(int $id): void
    {
        if (!Http::isPost()) {
            Http::redirect('/admin/admins');
        }
        $me = Auth::require();
        if ($id === (int)$me['id']) {
            Flashes::set('error', 'You cannot deactivate your own account.');
            Http::redirect('/admin/admins');
        }
        $db = Db::instance();
        $row = (array)$db->one(
            'SELECT id, username, is_active FROM admin_users WHERE id = ?',
            [$id]
        );
        if ($row === []) {
            Http::redirect('/admin/admins');
        }
        $active = (int)$row['is_active'] === 1 ? 0 : 1;
        $db->execute(
            'UPDATE admin_users SET is_active = ?, session_version = session_version + 1 WHERE id = ?',
            [$active, $id]
        );
        AuditLogger::record('admin', 'toggle', $id, (string)$row['username'],
            ['is_active' => (int)$row['is_active']],
            ['is_active' => $active], (int)$me['id']);
        Flashes::set('ok', $active === 1
            ? "«{$row['username']}» re-enabled."
            : "«{$row['username']}» disabled (their sessions are signed out).");
        Http::redirect('/admin/admins');
    }
}
