<?php
// Metadata-DB smoke test: verifies schema columns and the prepared-statement
// binder work against the real server. Safe: creates/drops its own table.

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

$expect = [
    'requests' => ['code', 'sql_text', 'response_mode', 'instant_cache_seconds',
        'delay_minutes', 'ttl_minutes', 'timeout_seconds', 'max_rows'],
    'executions' => ['request_id', 'mode', 'status', 'scheduled_at', 'truncated', 'error_code'],
    'connections' => ['db_type', 'password_encrypted', 'max_concurrency'],
    'calls' => ['id', 'was_shared', 'token_enc', 'idempotency_key_hash'],
    'webhook_deliveries' => ['state', 'next_retry_at'],
    'request_logs' => ['timestamp', 'event', 'duration_ms'],
    'admin_audit_log' => ['before_json'],
];

$bad = 0;
foreach ($expect as $table => $columns) {
    foreach ($columns as $column) {
        $n = db()->value(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        if ((int)$n === 0) {
            echo 'MISSING column: ', $table, '.', $column, PHP_EOL;
            $bad++;
        }
    }
}
echo $bad === 0 ? 'columns: PASS' : "columns: {$bad} problem(s)", PHP_EOL;

// Columns that were retired must be gone from the shipped schema: a leftover
// here means dead state is being created for no reader.
$absent = [
    'executions' => ['download_token_hash'],
];
foreach ($absent as $table => $columns) {
    foreach ($columns as $column) {
        $n = (int)db()->value(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        if ($n !== 0) {
            echo 'UNEXPECTED column: ', $table, '.', $column, PHP_EOL;
            $bad++;
        }
    }
}
echo $bad === 0 ? 'retired columns: PASS' : "retired columns: problem(s) counted above", PHP_EOL;

db()->execute('DROP TABLE IF EXISTS _gw_smoke');
db()->execute('CREATE TABLE _gw_smoke (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    s VARCHAR(64) NOT NULL,
    n INT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB');
db()->execute('INSERT INTO _gw_smoke (s, n, created_at) VALUES (?, ?, ?)', ['Ünïcode', null, now_str()]);
$row = db()->one('SELECT * FROM _gw_smoke WHERE id = ?', [db()->insertId()]);
$bound = $row !== null && $row['s'] === 'Ünïcode' && $row['n'] === null;
echo 'prepared bind/insert/select: ', $bound ? 'PASS' : 'FAIL', PHP_EOL;
if (!$bound) {
    $bad++;
}
db()->execute('DROP TABLE _gw_smoke');

echo 'session time_zone = ', db()->value('SELECT @@session.time_zone'), ' | NOW() = ', db()->value('SELECT NOW()'), PHP_EOL;
exit($bad === 0 ? 0 : 1);
