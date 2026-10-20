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
        'executions', 'calls', 'request_logs', 'admin_audit_log', 'webhook_deliveries', 'client_rate_limits',
        'worker_state'];
    $found = 0;
    foreach ($tables as $t) {
        $n = db()->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
        if ($n > 0) {
            $found++;
        }
    }
    $migration = 'apply sql\\migrations\\2026-10-20-production-hardening.sql';
    $ok('schema tables present', $found === count($tables),
        $found . '/' . count($tables) . ' found; ' . $migration);

    // Post-schema upgrade: calls.token_enc (webhook/status download links).
    $hasTokenEnc = (int)db()->value(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'calls' AND column_name = 'token_enc'"
    );
    $ok('calls.token_enc present (webhook links)', $hasTokenEnc === 1,
        'run the one-off ALTER in the pointer block at the end of sql\\schema.sql');

    // Post-schema upgrade: executions.error_code (sanitized failure reason for
    // the status API and webhook payloads). Failure paths write it, so a
    // missing column does not just hide the code: a run that fails cannot be
    // marked failed and can stay `running` until cleanup recovers it.
    $hasErrorCode = (int)db()->value(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'executions' AND column_name = 'error_code'"
    );
    $ok('executions.error_code present (failure payloads)', $hasErrorCode === 1,
        'failure paths write it; missing means failed runs cannot be marked - ' . $migration);

    // Post-schema upgrade: admin_users.session_version (the admin session
    // epoch). Every authenticated admin page reads it.
    $hasSessionVersion = (int)db()->value(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'session_version'"
    );
    $ok('admin_users.session_version present (admin session epoch)', $hasSessionVersion === 1,
        'a missing column breaks the panel - ' . $migration);

    // Post-schema upgrade: calls.idempotency_key_hash (Idempotency-Key
    // submits). EVERY call INSERT names the column (NULL without the header),
    // so a missing one breaks all submits, keyed or not.
    $hasIdemKey = (int)db()->value(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'calls' AND column_name = 'idempotency_key_hash'"
    );
    $ok('calls.idempotency_key_hash present (idempotent submits)', $hasIdemKey === 1,
        'every submit INSERT names this column; keyless submits break too - ' . $migration);

    // A webhook delivery stays 'pending' when the receiver is down or the
    // client lost its URL/secret: a growing backlog means clients are not
    // being told, and it also blocks call pruning.
    $pending = (int)db()->value("SELECT COUNT(*) FROM webhook_deliveries WHERE state = 'pending'");
    $pendingCap = max(1, (int)Core\Config::get('cleanup.pending_delivery_warn_count', 100));
    if ($pending > $pendingCap) {
        $warn("webhook backlog: {$pending} pending deliveries",
            "threshold {$pendingCap}; check the client URLs/secrets and the receiver");
    }
    $r = (array)Core\Config::get('cleanup.retention', []);
    echo '     retention days: logs=', (string)($r['request_logs_days'] ?? 0),
        ' calls=', (string)($r['calls_days'] ?? 0),
        ' executions=', (string)($r['executions_days'] ?? 0),
        ' deliveries=', (string)($r['webhook_deliveries_days'] ?? 0),
        ' audit=', (string)($r['audit_days'] ?? 0),
        ' (0 = keep forever)', PHP_EOL;

    // A delivery whose URL the SSRF policy blocks is exhausted instead of
    // sent, so surface saved client URLs that would fail today (loopback,
    // link-local/metadata and other reserved ranges are refused whatever the
    // config says). Bounded: every name costs a DNS lookup.
    $configured = 0;
    $blockedNames = [];
    foreach (db()->rows("SELECT name, webhook_url FROM clients WHERE webhook_url <> '' ORDER BY id LIMIT 50") as $client) {
        $configured++;
        if (Services\WebhookUrl::check((string)$client['webhook_url'])['error'] !== '') {
            $blockedNames[] = (string)$client['name'];
        }
    }
    if ($blockedNames === []) {
        $ok('client webhook URLs pass the SSRF policy', true, $configured . ' configured');
    } else {
        $warn(count($blockedNames) . ' client webhook URL(s) the gateway will refuse to post to',
            Core\Str::truncate(implode(', ', $blockedNames), 200)
            . ' - fix the URL on the client, or relax webhook.allow_*');
    }

    // SQL Server is the only target type whose transport this code configures
    // (MySQL and PostgreSQL take TLS from their own server-side setup), so
    // report the policy and shout when certificates are not validated.
    $sqlsrvActive = (int)db()->value("SELECT COUNT(*) FROM connections WHERE db_type = 'sqlserver' AND is_active = 1");
    $trustBad = (bool)Core\Config::get('connections.sqlsrv_trust_server_certificate', false);
    if ($sqlsrvActive > 0 && $trustBad) {
        $warn("{$sqlsrvActive} SQL Server connection(s) with certificate validation off",
            'any host on the path to the target database can impersonate it; install the certificate in the'
            . ' Windows trust store and unset GATEWAY_SQLSRV_TRUST_SERVER_CERT');
    }
    // This is the CONFIGURED stance, not a connectivity test: check.php must
    // not open target connections by default (an unreachable server would make
    // the pre-deploy gate slow or hang). A real login is proved by the admin
    // Test button or by running one request.
    echo '     sql server targets: ', (string)$sqlsrvActive, ' active, config: ',
        Core\Config::get('connections.sqlsrv_encrypt', true) ? 'encrypted' : 'PLAINTEXT',
        ', certificate ', ($trustBad ? 'accepted without validation' : 'validation on'),
        ' (connectivity not tested - use the admin Test button or a real request)',
        ', handshake cap ', (string)(int)Core\Config::get('connections.sqlsrv_login_timeout', 15), 's', PHP_EOL;

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

    // The heartbeat GET /health reads. check.php runs on the machine that owns
    // the schedule, so a silent worker_state here means the task is not firing
    // (or the upgrade did not create the table) - the same thing monitoring
    // would page about, visible before anyone probes /health. The age is the
    // newer of the pass's start and finish, exactly as GET /health computes it,
    // so a long pass that is still running is not reported as stale.
    $pass = \Services\WorkerState::lastPass();
    $age = \Services\WorkerState::passAgeSeconds($pass);
    $maxSilence = \Services\WorkerState::maxSilenceSeconds();
    if ($age === null) {
        $warn('worker heartbeat is recorded (worker_state)',
            'no pass in worker_state yet - register the worker task and ' . $migration);
    } elseif ($age > $maxSilence) {
        $warn('worker heartbeat is recent',
            "last pool pass started/finished {$age}s ago, worker.max_silence_seconds is {$maxSilence}s - GET /health answers 503");
    } else {
        $ok('worker heartbeat is recent', true, "newest pass evidence {$age}s old, limit {$maxSilence}s");
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

// Storage dir (result files live here; nothing else is written to storage/).
// A real create-and-remove probe, not is_writable(): the stat-based answer
// still says "writable" for some read-only mounts and ACLs, which is exactly
// the failure this check exists to catch. GET /health probes the same way, so
// the pre-deploy gate and the runtime answer cannot disagree.
$resultsDir = Core\Filesystem::resultsDir();
$storageDetail = '';
try {
    Core\Filesystem::prepare();
    // Unique per process: two concurrent checks must not unlink each other's
    // probe (and cleanup never touches these names - it only sweeps numeric
    // <execution id>.json[.tmp] files).
    $probe = $resultsDir . '/check.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($probe, 'x') === false) {
        $storageDetail = "cannot create a file in {$resultsDir}";
    } elseif (!@unlink($probe)) {
        $storageDetail = "cannot remove a file from {$resultsDir}";
    }
} catch (\Throwable $e) {
    $storageDetail = $e->getMessage();
}
$ok("storage dir writable: {$resultsDir}", $storageDetail === '', $storageDetail);

// Free-space floor for new result files (limits.min_free_disk_bytes): below it
// the worker refuses a run with storage_low instead of filling the volume.
$gib = static fn (int $bytes): string => number_format($bytes / 1073741824, 2) . ' GiB';
$minFree = (int)Core\Config::get('limits.min_free_disk_bytes', 0);
$free = Core\Filesystem::freeBytes();
if ($minFree <= 0) {
    $warn('limits.min_free_disk_bytes is disabled',
        'set a floor (2 GiB is the default) or a full volume fails mid-write');
} elseif ($free < 0) {
    $warn('free space on the storage volume', 'disk_free_space is unavailable here; the floor cannot be checked');
} elseif ($free < $minFree) {
    $warn('storage volume has room for new results',
        $gib($free) . ' free, floor is ' . $gib($minFree) . ' - new delayed runs fail with storage_low');
} else {
    $ok('storage volume has room for new results', true, $gib($free) . ' free, floor is ' . $gib($minFree));
}

// The active receiver rules (Services\WebhookUrl): printed because the defaults
// differ from what an internal deployment often has stored in clients.
$allowedHosts = (array)Core\Config::get('webhook.allowed_hosts', []);
echo '     webhook receivers: ',
    Core\Config::get('webhook.allow_http', false) ? 'http+https' : 'https only',
    ', loopback ', Core\Config::get('webhook.allow_loopback', false) ? 'allowed' : 'blocked',
    ', private/LAN ', Core\Config::get('webhook.allow_private_hosts', true) ? 'allowed' : 'blocked',
    ', allowlist ', ($allowedHosts === [] ? 'any host' : implode(',', $allowedHosts)),
    ' (link-local and reserved ranges are always blocked)', PHP_EOL;

// How the admin panel decides who a request came from, and how long a signed-in
// page stays alive: worth printing because a proxy in front of the panel
// silently turns the login throttle into a global one otherwise.
$proxies = (array)Core\Config::get('admin.trusted_proxies', []);
echo '     admin sessions: idle timeout ',
    (string)(int)Core\Config::get('admin.session_idle_minutes', 30), ' min, client IP = ',
    ($proxies === [] ? 'REMOTE_ADDR' : 'X-Forwarded-For behind ' . implode(',', $proxies)),
    PHP_EOL;

echo $failures === 0 ? '=== ALL CHECKS PASSED ===' : "=== {$failures} CHECK(S) FAILED ===",
    PHP_EOL;
exit($failures === 0 ? 0 : 1);
