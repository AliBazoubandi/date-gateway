<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Flashes;
use Admin\View;
use Core\Db;
use Core\Http;
use Core\Time;
use Services\AuditLogger;
use Services\Encryption;

final class ConnectionController
{
    private const TYPES = ['sqlserver', 'pgsql', 'mysql'];

    public static function list(): void
    {
        $rows = Db::instance()->rows(
            'SELECT c.*, (SELECT COUNT(*) FROM requests r WHERE r.connection_id = c.id) AS requests_count
             FROM connections c ORDER BY c.name'
        );
        View::render('connections/list', ['rows' => $rows], 'Connections', 'conn-list');
    }

    /** @param int $id 0 = new */
    public static function edit(int $id): void
    {
        $row = null;
        if ($id > 0) {
            $row = Db::instance()->one('SELECT id, name, db_type, host, port, db_name, username, max_concurrency, allow_writes, is_active FROM connections WHERE id = ?', [$id]);
            if ($row === null) {
                Flashes::set('error', 'Connection not found.');
                Http::redirect('/admin/connections');
            }
        }
        View::render('connections/edit', ['row' => $row], $row === null ? 'New connection' : 'Edit connection');
    }

    /** @param int $id 0 = create */
    public static function save(int $id): void
    {
        $db = Db::instance();
        $in = self::input();
        $errors = self::validate($in, $id);
        if ($errors !== []) {
            // Re-render with everything the admin typed plus a red border on
            // the offending field — a redirect would blank the whole form.
            View::render('connections/edit',
                ['row' => self::rowFromInput($in, $id), 'errors' => $errors],
                $id > 0 ? 'Edit connection' : 'New connection');
            return;
        }

        $now = Time::now();
        $adminId = (int)($_SESSION['admin_user_id'] ?? 0);

        if ($id > 0) {
            $before = self::snapshotRow($db->one('SELECT * FROM connections WHERE id = ?', [$id]));
            $passwordChanged = $in['password'] !== '';
            $db->execute(
                'UPDATE connections SET name=?, db_type=?, host=?, port=?, db_name=?, username=?,
                    max_concurrency=?, allow_writes=?, is_active=?, updated_by=?, updated_at=?
                 WHERE id=?',
                [$in['name'], $in['db_type'], $in['host'], $in['port'] === 0 ? null : $in['port'],
                    $in['db_name'], $in['username'], $in['max_concurrency'], $in['allow_writes'],
                    $in['is_active'], $adminId, $now, $id]
            );
            if ($passwordChanged) {
                $db->execute('UPDATE connections SET password_encrypted = ? WHERE id = ?',
                    [Encryption::encrypt($in['password']), $id]);
            }
            $after = self::snapshotRow($db->one('SELECT * FROM connections WHERE id = ?', [$id]));
            $after['password'] = $passwordChanged ? '(changed)' : '(unchanged)';
            AuditLogger::record('connection', 'update', $id, $in['name'], $before, $after);
            Flashes::set('ok', 'Connection updated.');
        } else {
            // Empty password is allowed for intranet accounts (stored encrypted).
            $db->execute(
                'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted, max_concurrency, allow_writes, is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$in['name'], $in['db_type'], $in['host'], $in['port'] === 0 ? null : $in['port'],
                    $in['db_name'], $in['username'], Encryption::encrypt($in['password']),
                    $in['max_concurrency'], $in['allow_writes'], $in['is_active'], $adminId, $now]
            );
            $id = $db->insertId();
            $after = self::snapshotRow($db->one('SELECT * FROM connections WHERE id = ?', [$id]));
            $after['password'] = '(set)';
            AuditLogger::record('connection', 'create', $id, $in['name'], null, $after);
            Flashes::set('ok', 'Connection created.');
        }
        Http::redirect('/admin/connections');
    }

    public static function delete(int $id): void
    {
        $db = Db::instance();
        $row = $db->one('SELECT * FROM connections WHERE id = ?', [$id]);
        if ($row === null) {
            Flashes::set('error', 'Connection not found.');
            Http::redirect('/admin/connections');
        }
        $inUse = (int)$db->value('SELECT COUNT(*) FROM requests WHERE connection_id = ?', [$id]);
        if ($inUse > 0) {
            Flashes::set('error', "Cannot delete connection “{$row['name']}”: it is used by {$inUse} request(s). Delete or reassign those requests first.");
            Http::redirect('/admin/connections');
        }
        $db->execute('DELETE FROM connections WHERE id = ?', [$id]);
        AuditLogger::record('connection', 'delete', $id, (string)$row['name'], self::snapshotRow($row), null);
        Flashes::set('ok', 'Connection «' . $row['name'] . '» deleted.');
        Http::redirect('/admin/connections');
    }

    /** @return array<string,mixed> */
    private static function input(): array
    {
        $type = strtolower(trim((string)Http::param('db_type', 'p')));
        $port = trim((string)(Http::param('port', 'p') ?? ''));
        $mc = trim((string)(Http::param('max_concurrency', 'p') ?? ''));
        return [
            'name' => trim((string)Http::param('name', 'p')),
            'db_type' => in_array($type, self::TYPES, true) ? $type : '',
            'host' => trim((string)Http::param('host', 'p')),
            'port' => $port !== '' && ctype_digit($port) && (int)$port > 0 && (int)$port <= 65535 ? (int)$port : ($port === '' ? null : 0),
            'db_name' => trim((string)Http::param('db_name', 'p')),
            'username' => trim((string)Http::param('username', 'p')),
            'password' => (string)Http::param('password', 'p'),
            'max_concurrency' => $mc === '' ? null : (ctype_digit($mc) && (int)$mc > 0 ? (int)$mc : 0),
            'allow_writes' => Http::param('allow_writes', 'p') !== null ? 1 : 0,
            'is_active' => Http::param('is_active', 'p') !== null ? 1 : 0,
        ];
    }

    /** The shape connections/edit.php renders, rebuilt from the POST input. */
    private static function rowFromInput(array $in, int $id): array
    {
        return ['id' => $id] + $in; // password is never echoed back
    }

    /** @return array<string,string> field name => error message */
    private static function validate(array $in, int $id): array
    {
        $e = [];
        if ($in['name'] === '') {
            $e['name'] = 'Name is required.';
        }
        if ($in['db_type'] === '') {
            $e['db_type'] = 'Pick a database type (sqlserver, pgsql or mysql).';
        }
        if ($in['host'] === '') {
            $e['host'] = 'Host is required.';
        }
        if ($in['port'] === 0) {
            $e['port'] = 'Port must be an integer between 1 and 65535 (or empty).';
        }
        if ($in['db_name'] === '') {
            $e['db_name'] = 'Database name is required.';
        }
        if ($in['username'] === '') {
            $e['username'] = 'Username is required.';
        }
        if ($in['max_concurrency'] === 0) {
            $e['max_concurrency'] = 'Max concurrency must be a positive integer (or leave empty for the default).';
        }
        if ($in['allow_writes'] === 1 && $in['db_type'] !== 'sqlserver') {
            // Only the sqlsrv driver can stream a multi-statement batch.
            $e['allow_writes'] = 'Scripts mode (allow writes) is only available for SQL Server connections.';
        }
        // An empty password IS allowed (some intranet MySQL accounts have
        // none) — it is stored as an encrypted blob so the DB stays opaque.
        return $e;
    }

    /** Audit snapshot with the encrypted password masked. */
    private static function snapshotRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (isset($row['password_encrypted'])) {
            $row['password'] = '(stored, encrypted)';
            unset($row['password_encrypted']);
        }
        return $row;
    }
}
