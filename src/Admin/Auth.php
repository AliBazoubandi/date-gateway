<?php
declare(strict_types=1);
namespace Admin;

use Core\Config;
use Core\Db;
use Core\Session;
use Core\Str;
use Core\Time;
use Services\AuditLogger;

/**
 * Admin authentication: throttled login/lockout, session binding, guard.
 */
final class Auth
{
    /** True when this request dropped a live session for idling too long. */
    private static bool $idleExpired = false;

    /** Redirects to the login page when there is no valid session. */
    public static function require(): array
    {
        $admin = self::current();
        if ($admin === null) {
            header('Location: /admin/login' . (self::$idleExpired ? '?expired=1' : ''));
            exit;
        }
        return $admin;
    }

    /**
     * The signed-in admin, or null. A session is valid only while the account
     * is active, the idle window (admin.session_idle_minutes) has not passed,
     * and the stored session_version still matches the account's — so rotating
     * a password with cli\create_admin.php signs out every other browser.
     */
    public static function current(): ?array
    {
        Session::start();
        $id = (int)($_SESSION['admin_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $idleMinutes = (int)Config::get('admin.session_idle_minutes', 30);
        $lastSeen = (int)($_SESSION['last_seen'] ?? 0);
        if ($idleMinutes > 0 && $lastSeen > 0 && time() - $lastSeen > $idleMinutes * 60) {
            self::$idleExpired = true;
            AuditLogger::record('admin', 'logout', $id, self::sessionName(), null,
                ['reason' => 'idle', 'idle_minutes' => $idleMinutes], $id);
            Session::destroy();
            return null;
        }

        $admin = Db::instance()->one(
            'SELECT id, username, is_active, session_version FROM admin_users WHERE id = ? AND is_active = 1',
            [$id]
        );
        if ($admin === null) { // deleted or deactivated mid-session
            Session::destroy();
            return null;
        }
        if ((int)($_SESSION['session_version'] ?? -1) !== (int)$admin['session_version']) {
            // Password rotated (or a session predating the column): void.
            Session::destroy();
            return null;
        }
        $_SESSION['last_seen'] = time();
        return $admin;
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
        // Deliberately NOT audited: this path is reachable without any
        // credentials at all, so every refused probe would add a row to
        // admin_audit_log. It repeats the same words as a wrong password.
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
            self::auditFailure($username, $ip, 'unknown_username', null);
            return 'Wrong username or password.';
        }
        if (!$user['is_active']) {
            $rememberIpFailure();
            self::auditFailure($username, $ip, 'inactive_account', (int)$user['id']);
            return 'Wrong username or password.';
        }
        if (!empty($user['locked_until']) && $user['locked_until'] > $now) {
            $rememberIpFailure();
            self::auditFailure($username, $ip, 'locked_account', (int)$user['id']);
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
            self::auditFailure($username, $ip, $locked !== null ? 'wrong_password_locked' : 'wrong_password', (int)$user['id']);
            return $msg;
        }

        Session::regenerate();
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = (string)$user['username'];
        $_SESSION['session_version'] = (int)$user['session_version'];
        $_SESSION['last_seen'] = time();
        $db->execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        $db->execute('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [$now, $user['id']]);
        AuditLogger::record('admin', 'login', (int)$user['id'], (string)$user['username'],
            null, ['ip' => $ip], (int)$user['id']);
        return null;
    }

    public static function logout(): void
    {
        Session::start();
        $id = (int)($_SESSION['admin_user_id'] ?? 0);
        if ($id > 0) {
            AuditLogger::record('admin', 'logout', $id, self::sessionName(), null,
                ['reason' => 'manual'], $id);
        }
        Session::destroy();
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * A refused sign-in attempt. The attempted username is attacker-supplied
     * text (and unbounded), so it is byte-truncated to fit entity_name and
     * never accompanied by the password or any hash.
     */
    private static function auditFailure(string $username, string $ip, string $reason, ?int $adminId): void
    {
        AuditLogger::record('admin', 'login_failed', $adminId, Str::truncate(trim($username), 190),
            null, ['ip' => $ip, 'reason' => $reason], $adminId ?? 0);
    }

    private static function sessionName(): string
    {
        return (string)($_SESSION['admin_username'] ?? '');
    }
}
