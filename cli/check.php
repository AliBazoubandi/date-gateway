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
$warn = static function (string $label, string $detail = ''): void {
    echo 'WARN ', $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
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

$baseUrl = trim((string)Core\Config::get('app.base_url', ''));
if ($baseUrl === '') {
    $ok('app.base_url is set (download/webhook links need it)', false,
        'set GATEWAY_BASE_URL=https://<your-host> in config/.env');
} else {
    $valid = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false;
    $ok('app.base_url is a valid URL', $valid, $valid ? $baseUrl : "'{$baseUrl}' is not a valid URL");
    if ($valid) {
        $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
        $debug = (bool)Core\Config::get('app.debug', false);
        $ok('app.base_url uses https', $scheme === 'https' || $debug,
            $scheme === 'https' ? '' : "scheme is '{$scheme}'" . ($debug ? ' (allowed: GATEWAY_DEBUG=1)' : ''));
    }
}

foreach (['mysqli' => 'metadata DB driver', 'openssl' => 'AES-256-GCM', 'curl' => 'webhooks'] as $ext => $why) {
    $ok("extension {$ext} ({$why})", extension_loaded($ext));
}
foreach (['sqlsrv' => 'SQL Server targets', 'pgsql' => 'PostgreSQL targets'] as $ext => $why) {
    $loaded = extension_loaded($ext);
    $ok("extension {$ext} ({$why})", $loaded, $loaded ? '' : 'enable in php.ini when you need this DB type');
}

// Instant mode buffers rows in memory to build the inline JSON response, capped
// by limits.instant.max_result_bytes. memory_limit must leave real headroom
// above that cap or a large result aborts the PHP process mid-request (P1-2).
$toBytes = static function (string $v): int {
    $v = strtolower(trim($v));
    $unit = substr($v, -1);
    $num = (int)$v;
    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
};
$memLimit = (string)(ini_get('memory_limit') ?: '');
$instantCap = (int)Core\Config::get('limits.instant.max_result_bytes', 0);
if ($memLimit === '-1' || $toBytes($memLimit) >= 256 * 1024 * 1024) {
    $ok('PHP memory_limit >= 256M (instant inline results)', true, "memory_limit = {$memLimit}");
} else {
    $warn('PHP memory_limit >= 256M (instant inline results)',
        "memory_limit = {$memLimit}; instant cap is " . number_format($instantCap / 1048576, 1) .
        " MiB of buffered rows - raise memory_limit in php.ini");
}

// Metadata DB.
try {
    $v = db()->value('SELECT 1');
    $ok('metadata DB connect + SELECT 1', (string)$v === '1');
    $tz = db()->value('SELECT @@session.time_zone');
    echo '     session time_zone = ', (string)$tz, ', now = ', (string)db()->value('SELECT NOW()'), PHP_EOL;

    $tables = ['admin_users', 'login_attempts', 'connections', 'requests', 'clients', 'client_request_permissions',
        'executions', 'calls', 'request_logs', 'admin_audit_log', 'webhook_deliveries', 'client_rate_limits'];
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

    // Post-schema upgrade: executions.error_code (sanitized failure reason for
    // the status API and webhook payloads).
    $hasErrorCode = (int)db()->value(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'executions' AND column_name = 'error_code'"
    );
    $ok('executions.error_code present (failure payloads)', $hasErrorCode === 1,
        'run the ALTER at the end of sql\\schema.sql if missing');

    // A delayed timeout at or above the stale-running threshold means the worker
    // could fail a legitimately-running query; the admin UI caps at 1800s (30min).
    $staleMinutes = max(1, (int)Core\Config::get('worker.stale_running_minutes', 90));
    $overlap = (int)db()->value(
        'SELECT COUNT(*) FROM requests WHERE timeout_seconds >= ?',
        [$staleMinutes * 60]
    );
    if ($overlap > 0) {
        $warn("{$overlap} request(s) have timeout_seconds >= worker.stale_running_minutes",
            'lower the timeout or raise stale_running_minutes');
    }
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
