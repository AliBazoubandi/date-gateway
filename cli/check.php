<?php
// ============================================================================
// Data Gateway - system check (run: php cli\check.php)
// Verifies config, extensions, metadata DB, timezone and storage dirs.
// Exit code 0 = all good, 1 = problems found.
// ============================================================================

$failures = 0;
$ok = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

echo '=== Data Gateway system check ===', PHP_EOL;

// Bootstrap (also validates config file and sets the timezone).
try {
    require __DIR__ . '/../src/Core/bootstrap.php';
    $ok('config/config.php loads', true);
} catch (\Throwable $e) {
    $ok('config/config.php loads', false, $e->getMessage());
    echo 'Abort.', PHP_EOL;
    exit(1);
}

$ok('Time zone is Asia/Tehran', date_default_timezone_get() === 'Asia/Tehran', date_default_timezone_get());
echo '     now = ', now_str(), '  (ISO: ', iso8601(now_str()) ?? '-', ')', PHP_EOL;

foreach (['mysqli' => 'metadata DB driver', 'openssl' => 'AES-256-GCM', 'curl' => 'webhooks'] as $ext => $why) {
    $ok("extension {$ext} ({$why})", extension_loaded($ext));
}
foreach (['sqlsrv' => 'SQL Server targets', 'pgsql' => 'PostgreSQL targets'] as $ext => $why) {
    $loaded = extension_loaded($ext);
    $ok("extension {$ext} ({$why})", $loaded, $loaded ? '' : 'enable in php.ini when you need this DB type');
}

// Metadata DB.
try {
    $v = db()->value('SELECT 1');
    $ok('metadata DB connect + SELECT 1', (string)$v === '1');
    $tz = db()->value('SELECT @@session.time_zone');
    echo '     session time_zone = ', (string)$tz, ', now = ', (string)db()->value('SELECT NOW()'), PHP_EOL;

    $tables = ['admin_users', 'connections', 'requests', 'clients', 'client_request_permissions',
        'executions', 'calls', 'request_logs', 'admin_audit_log', 'webhook_deliveries'];
    $found = 0;
    foreach ($tables as $t) {
        $n = db()->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
        if ($n > 0) {
            $found++;
        }
    }
    $ok('schema tables present', $found === count($tables), $found . '/' . count($tables) . ' found; import sql\\schema.sql if lower');

    // Post-schema upgrade: calls.token_enc (webhook/status download links).
    $hasTokenEnc = (int)db()->value(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'calls' AND column_name = 'token_enc'"
    );
    $ok('calls.token_enc present (webhook links)', $hasTokenEnc === 1,
        'run the ALTER at the end of sql\\schema.sql if missing');
} catch (\Throwable $e) {
    $ok('metadata DB', false, $e->getMessage());
}

// Master key (format + placeholder/retired rejection).
try {
    \Services\Encryption::assertUsableKey((string)Core\Config::get('security.master_key', ''));
    $ok('security.master_key is a real, non-placeholder 64-hex key', true);
} catch (\Throwable $e) {
    $ok('security.master_key is a real, non-placeholder 64-hex key', false, $e->getMessage());
}

// Storage dirs.
$storage = Core\Filesystem::storageDir();
foreach (['results', 'cache'] as $sub) {
    $dir = $storage . '/' . $sub;
    $ok("storage dir writable: {$dir}", is_dir($dir) && is_writable($dir), is_dir($dir) ? '' : 'missing');
}

echo $failures === 0 ? '=== ALL CHECKS PASSED ===' : "=== {$failures} CHECK(S) FAILED ===",
    PHP_EOL;
exit($failures === 0 ? 0 : 1);
