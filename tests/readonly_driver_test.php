<?php
// ============================================================================
// Integration test: the MySQL target session refuses writes (P1-6).
//
// SqlGuard is a keyword scanner; this proves the second line of defence -
// MySqlDriver opens every target session inside START TRANSACTION READ ONLY,
// so DML fails at the server even when the account holds full DML rights and
// the scanner is bypassed by calling the driver directly.
//
// NOTE ON SCOPE: a MySQL read-only transaction blocks writes to transactional
// tables (INSERT/UPDATE/DELETE/REPLACE...). It does NOT block DDL, which
// implicitly commits outside the transaction - DROP/CREATE are stopped by
// SqlGuard's keyword scan instead, and the target login should be granted
// SELECT only (see README). This test asserts both halves honestly.
//
// Runs only inside the throwaway test database (test_guard); the connection
// parameters are the metadata DB's own, which the gateway user CAN write to -
// that is what makes the control visible.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';
require __DIR__ . '/../src/Core/Filesystem.php';

test_guard();

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$dbName = (string)\Core\Config::get('metadata_db.database', '');
$params = [
    'db_type' => 'mysql',
    'host' => (string)\Core\Config::get('metadata_db.host', '127.0.0.1'),
    'port' => (int)\Core\Config::get('metadata_db.port', 3306),
    'db' => $dbName,
    'user' => (string)\Core\Config::get('metadata_db.user', ''),
    'password' => (string)\Core\Config::get('metadata_db.password', ''),
    'timeout' => 15,
];

$probe = 'gw_ro_probe_' . substr(\Core\Str::hex(4), 0, 8);
$db = db();
$db->execute("CREATE TABLE {$probe} (id INT NOT NULL, note VARCHAR(32) NOT NULL)");

try {
    // Control: the same account, outside the driver's read-only session, CAN write.
    $db->execute("INSERT INTO {$probe} (id, note) VALUES (1, 'baseline')");
    $check('control: the account itself has write rights',
        (int)$db->value("SELECT COUNT(*) FROM {$probe}") === 1);

    $driver = \Db\ConnectionFactory::fromParams($params);
    try {
        // Read path still works inside the read-only session.
        $stmt = $driver->query('SELECT id, note FROM ' . $probe);
        $row = $driver->fetchRow($stmt);
        $check('read-only session still serves SELECT', is_array($row) && (int)$row['id'] === 1);
        $driver->free($stmt);

        $expl = $driver->query('EXPLAIN SELECT 1');
        $check('read-only session serves EXPLAIN', $driver->fetchRow($expl) !== null);
        $driver->free($expl);

        $show = $driver->query("SHOW TABLES LIKE '{$probe}'");
        $check('read-only session serves SHOW', $driver->fetchRow($show) !== null);
        $driver->free($show);

        // DML, bypassing SqlGuard entirely, must be refused by the server.
        foreach ([
            'INSERT' => "INSERT INTO {$probe} (id, note) VALUES (2, 'smuggled')",
            'UPDATE' => "UPDATE {$probe} SET note = 'smuggled' WHERE id = 1",
            'DELETE' => "DELETE FROM {$probe} WHERE id = 1",
            'REPLACE' => "REPLACE INTO {$probe} (id, note) VALUES (1, 'smuggled')",
        ] as $verb => $write) {
            $why = '';
            $refused = false;
            try {
                $driver->query($write);
            } catch (\Db\DbQueryException $e) {
                $refused = true;
                $why = $e->getMessage();
            }
            $check("driver refuses {$verb} (SqlGuard bypassed)", $refused, $why);
            $check("{$verb} refusal is the READ ONLY transaction",
                $refused && (stripos($why, 'read only') !== false || str_contains($why, '1792')),
                $why);
        }
    } finally {
        $driver->close();
    }

    $check('no write reached the table',
        (int)$db->value("SELECT COUNT(*) FROM {$probe}") === 1
        && (string)$db->value("SELECT note FROM {$probe} WHERE id = 1") === 'baseline');

    // Documented scope limit: DDL is NOT blocked by the read-only transaction,
    // so it stays covered by the keyword scan instead. Assert that, otherwise a
    // future reader would over-trust the session guard.
    $ddlBlockedByGuard = false;
    try {
        \Services\SqlGuard::assertReadOnly("DROP TABLE {$probe}");
    } catch (\RuntimeException) {
        $ddlBlockedByGuard = true;
    }
    $check('DDL (unblocked by the session) is refused by SqlGuard instead', $ddlBlockedByGuard);
} finally {
    $db->execute("DROP TABLE IF EXISTS {$probe}");
}

echo $failures === 0 ? '=== READONLY DRIVER TEST PASS ===' : "=== READONLY DRIVER TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
