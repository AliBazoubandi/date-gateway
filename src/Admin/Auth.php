<?php
declare(strict_types=1);
namespace Admin;

use Core\Config;
use Core\Db;
use Core\Session;
use Core\Str;
use Core\Time;

/**
 * Admin authentication: throttled login/lockout, session binding, guard.
 */
final class Auth
{
    /** Redirects to the login page when there is no valid session. */
    public static function require(): array
    {
        $admin = self::current();
        if ($admin === null) {
            header('Location: /admin/login');
            exit;
        }
        return $admin;
    }

    public static function current(): ?array
    {
        Session::start();
        $id = (int)($_SESSION['admin_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        return Db::instance()->one(
            'SELECT id, username, is_active FROM admin_users WHERE id = ? AND is_active = 1',
            [$id]
        );
    }

    /** @return string|null error message, or null on success */
    public static function login(string $username, string $password): ?string
    {
        $db = Db::instance();
        $maxAttempts = (int)Config::get('admin.max_attempts', 5);
        $lockoutMinutes = (int)Config::get('admin.lockout_minutes', 15);
        $now = Time::now();
        $ip = client_ip();

        // ---- IP throttle: >=3 failures from one address inside the window
        // get a generic refusal before the password check even runs. Rows
        // whose window has passed are purged, so what remains is active.
        $db->execute('DELETE FROM login_attempts WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
        $ipRow = $db->one('SELECT failed_count FROM login_attempts WHERE ip = ?', [$ip]);
        if ($ipRow !== null && (int)$ipRow['failed_count'] >= 3) {
            return 'Too many login attempts from this address. Try again later.';
        }
        $rememberIpFailure = static function () use ($db, $ip, $now, $ipRow): void {
            if ($ipRow === null) {
                $db->execute(
                    'INSERT INTO login_attempts (ip, failed_count, window_start, last_attempt_at) VALUES (?, 1, ?, ?)',
                    [$ip, $now, $now]
                );
            } else {
                $db->execute(
                    'UPDATE login_attempts SET failed_count = failed_count + 1, last_attempt_at = ? WHERE ip = ?',
                    [$now, $ip]
                );
            }
        };

        $user = $db->one('SELECT * FROM admin_users WHERE username = ?', [$username]);
        if ($user === null) {
            // Burn one bcrypt round so response timing doesn't reveal whether
            // the username exists; answer with the same words as below. The
            // lockout/attempt counters only apply to real accounts.
            password_verify($password, password_hash('gateway-dummy', PASSWORD_DEFAULT));
            $rememberIpFailure();
            return 'Wrong username or password.';
        }
        if (!$user['is_active']) {
            $rememberIpFailure();
            return 'Wrong username or password.';
        }
        if (!empty($user['locked_until']) && $user['locked_until'] > $now) {
            $rememberIpFailure();
            return 'Locked. Try again later (' . $user['locked_until'] . ').';
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            $attempts = (int)$user['failed_attempts'] + 1;
            $locked = null;
            $msg = 'Wrong username or password.';
            $rememberIpFailure();
            if ($attempts >= $maxAttempts) {
                $locked = Time::mysql(Time::addSeconds(new \DateTimeImmutable($now, Time::timezone()), $lockoutMinutes * 60));
                $db->execute('UPDATE admin_users SET failed_attempts = 0, locked_until = ? WHERE id = ?', [$locked, $user['id']]);
                $msg = "Locked for {$lockoutMinutes} minute(s) after {$maxAttempts} failed attempts.";
            } else {
                $db->execute('UPDATE admin_users SET failed_attempts = ? WHERE id = ?', [$attempts, $user['id']]);
                $msg .= " Attempts left: " . ($maxAttempts - $attempts) . ".";
            }
            return $msg;
        }

        Session::regenerate();
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $db->execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        $db->execute('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [$now, $user['id']]);
        return null;
    }

    public static function logout(): void
    {
        Session::start();
        Session::destroy();
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
