<?php
// tests/retention_test.php — row retention (cleanup.retention.*) and the
// free-space floor (limits.min_free_disk_bytes).
//
// The parent process keeps the SHIPPED defaults (every window 0) so it can
// prove an upgrade deletes nothing. The real prune runs in a child process that
// receives the windows through the environment, because config is read at
// bootstrap: php tests\retention_test.php --prune
//
// Asserted: pending deliveries keep their call, settled deliveries of a pruned
// call go before it (fk_whd_call is RESTRICT), an execution survives while a
// call points at it, live (scheduled) work is never pruned, files go with their
// rows, >1000 old rows need several batches, and a volume under the floor makes
// the worker fail a run with storage_low before writing anything.
// Usage: php tests\retention_test.php

use Core\Config;
use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Encryption;
use Services\StorageGuard;
use Worker\Cleanup;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();
Filesystem::prepare();

$db = db();

// Child phase: one cleanup pass under the retention windows, then straight
// out — before any fixture is created and without the shutdown wipe.
if (($argv[1] ?? '') === '--prune') {
    echo json_encode([
        'windows' => (array)Config::get('cleanup.retention', []),
        'summary' => Cleanup::run(),
    ]), PHP_EOL;
    exit(0);
}

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label,
        $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$run = substr(Str::hex(4), 0, 8);
$ago = static fn (int $days): string => Time::mysql(Time::addSeconds(Time::nowDt(), -$days * 86400));
$now = now_str();

// --------------------------- fixtures + self-clean ---------------------------
$connectionIds = $requestIds = $clientIds = [];
$wipe = static function () use ($db, $run, &$connectionIds, &$requestIds, &$clientIds): void {
    if ($requestIds !== []) {
        $r = implode(',', array_map('intval', $requestIds));
        foreach ($db->rows("SELECT id, result_path FROM executions WHERE request_id IN ($r)") as $row) {
            Cleanup::safeUnlink('results/' . (int)$row['id'] . '.json');
            Cleanup::safeUnlink('results/' . (int)$row['id'] . '.json.tmp');
            if ($row['result_path'] !== null) {
                Cleanup::safeUnlink((string)$row['result_path']);
            }
        }
        $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE request_id IN ($r))");
        $db->execute("DELETE FROM calls WHERE request_id IN ($r)");
        $db->execute("DELETE FROM executions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM client_request_permissions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM request_logs WHERE request_id IN ($r)");
        $db->execute("DELETE FROM requests WHERE id IN ($r)");
    }
    if ($clientIds !== []) {
        $c = implode(',', array_map('intval', $clientIds));
        $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE client_id IN ($c))");
        $db->execute("DELETE FROM calls WHERE client_id IN ($c)");
        $db->execute("DELETE FROM client_request_permissions WHERE client_id IN ($c)");
        $db->execute("DELETE FROM request_logs WHERE client_id IN ($c)");
        $db->execute("DELETE FROM clients WHERE id IN ($c)");
    }
    // Audit rows have no FK to the fixtures, so they go by this run's marker.
    $db->execute('DELETE FROM admin_audit_log WHERE entity_name LIKE ?', ["rtk{$run}%"]);
    if ($connectionIds !== []) {
        $db->execute('DELETE FROM connections WHERE id IN (' . implode(',', array_map('intval', $connectionIds)) . ')');
    }
};
register_shutdown_function($wipe);

$db->execute(
    'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted,
        max_concurrency, is_active, created_at)
     VALUES (?, ?, ?, NULL, ?, ?, ?, 1, 1, ?)',
    ["rtk {$run} conn", 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), $now]
);
$conn = $db->insertId();
$connectionIds[] = $conn;

$mkRequest = static function (string $code) use ($db, $now, $conn): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
            delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, 15, 10, 1000, 1, ?)',
        [$code, $code, 'SELECT 1 AS ping', $conn, 'delayed', $now]
    );
    return $db->insertId();
};
$reqPrune = $mkRequest("rtk{$run}.prune");
$reqDisk = $mkRequest("rtk{$run}.disk");
$requestIds[] = $reqPrune;
$requestIds[] = $reqDisk;

$db->execute(
    'INSERT INTO clients (name, api_key_hash, webhook_url, webhook_secret, is_active, created_at)
     VALUES (?, ?, NULL, NULL, 1, ?)',
    ["rtk{$run} client", hash('sha256', 'rtk' . $run), $now]
);
$clientId = $db->insertId();
$clientIds[] = $clientId;

$mkExec = static function (array $fields) use ($db, $reqPrune, $ago): int {
    $row = array_merge([
        'request_id' => $reqPrune,
        'mode' => 'delayed',
        'status' => 'failed',
        'scheduled_at' => $ago(60),
        'finished_at' => $ago(60),
    ], $fields);
    $db->execute('INSERT INTO executions (' . implode(', ', array_keys($row)) . ') VALUES ('
        . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
    return $db->insertId();
};
$mkFile = static function (int $execId, string $suffix = '.json'): string {
    $path = Filesystem::resultsDir() . '/' . $execId . $suffix;
    file_put_contents($path, '{"data":[],"meta":{}}');
    return $path;
};

// Old ready run holding a file nobody else owns: prune must delete both.
$execFile = $mkExec(['status' => 'ready']);
$db->execute('UPDATE executions SET result_path = ? WHERE id = ?', ['results/' . $execFile . '.json', $execFile]);
$fileOfExecFile = $mkFile($execFile);

// Old expired run whose result row is gone but a .tmp sibling survived.
$execTmp = $mkExec(['status' => 'expired']);
$tmpSibling = $mkFile($execTmp, '.json.tmp');

// Old run still pointed at by an old call: call goes first, then the run.
$execOldCall = $mkExec(['status' => 'expired']);
// Old run pointed at by a RECENT call: must survive.
$execYoungCall = $mkExec(['status' => 'expired']);
$execYoungCallFile = $mkFile($execYoungCall);
// Live work: an old scheduled row is never pruned.
$execScheduled = $mkExec(['status' => 'scheduled', 'scheduled_at' => $ago(60), 'finished_at' => null]);

$mkCall = static function (string $created, ?int $exec) use ($db, $clientId, $reqPrune, $run): string {
    $callId = Str::hex(16);
    $db->execute(
        'INSERT INTO calls (id, client_id, request_id, execution_id, created_at, ip, was_shared)
         VALUES (?, ?, ?, ?, ?, ?, 0)',
        [$callId, $clientId, $reqPrune, $exec, $created, "rtk{$run}"]
    );
    return $callId;
};
$mkDelivery = static function (string $callId, string $state, string $created) use ($db): int {
    $db->execute(
        'INSERT INTO webhook_deliveries (call_id, state, created_at) VALUES (?, ?, ?)',
        [$callId, $state, $created]
    );
    return $db->insertId();
};

$callPlain = $mkCall($ago(60), null);
$callPending = $mkCall($ago(60), null);
$deliveryPending = $mkDelivery($callPending, 'pending', $ago(60));
// A settled delivery the deliveries window has not reached yet, attached to an
// out-of-window call: pruning the call has to remove it first.
$callYoungDelivery = $mkCall($ago(60), null);
$deliveryYoung = $mkDelivery($callYoungDelivery, 'sent', $ago(5));
$callOnOldExec = $mkCall($ago(60), $execOldCall);
$callYoung = $mkCall($ago(2), $execYoungCall);
$callYoungDelivery2 = $mkCall($ago(20), null);
$deliveryOfYoungCall = $mkDelivery($callYoungDelivery2, 'sent', $ago(20));

$mkLog = static function (string $when, int $n = 1) use ($db, $clientId, $reqPrune, $run): void {
    for ($i = 0; $i < $n; $i++) {
        $db->execute(
            'INSERT INTO request_logs (`timestamp`, client_id, request_id, event, ip, message)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$when, $clientId, $reqPrune, 'submitted', 'rtk', "rtk{$run} log {$i}"]
        );
    }
};
$mkLog($ago(60), 2);
$mkLog($ago(2), 2);
// Past one batch: pruning these needs more than a single DELETE.
$mkLog($ago(60), 1200);

$db->execute(
    'INSERT INTO admin_audit_log (`timestamp`, admin_user_id, entity_type, entity_id, entity_name, action)
     VALUES (?, NULL, ?, 1, ?, ?)',
    [$ago(60), 'client', "rtk{$run} audit old", 'create']
);
$db->execute(
    'INSERT INTO admin_audit_log (`timestamp`, admin_user_id, entity_type, entity_id, entity_name, action)
     VALUES (?, NULL, ?, 1, ?, ?)',
    [$ago(2), 'client', "rtk{$run} audit new", 'create']
);

$liveExecs = [$execFile, $execTmp, $execOldCall, $execYoungCall, $execScheduled];
$liveCalls = [$callPlain, $callPending, $callYoungDelivery, $callOnOldExec, $callYoung, $callYoungDelivery2];
$countIn = static fn (string $sql, array $params = []): int => (int)$db->value($sql, $params);

// ------------------- phase 1: shipped defaults prune nothing -----------------
$retention = (array)Config::get('cleanup.retention', []);
$check('every retention window defaults to 0', $retention !== []
    && !array_filter($retention, static fn ($v): bool => (int)$v !== 0),
    json_encode($retention));
$check('log_retention_days still defaults to 0',
    (int)Config::get('cleanup.log_retention_days', -1) === 0);

$s0 = Cleanup::run();
$check('a default cleanup pass prunes no rows', ($s0['logs_removed'] ?? -1) === 0
    && ($s0['calls_removed'] ?? -1) === 0 && ($s0['executions_removed'] ?? -1) === 0
    && ($s0['deliveries_removed'] ?? -1) === 0 && ($s0['audit_removed'] ?? -1) === 0,
    json_encode($s0));
$check('all fixtures survive the default pass',
    $countIn('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqPrune]) === count($liveExecs)
    && $countIn('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqPrune]) === count($liveCalls)
    && $countIn('SELECT COUNT(*) FROM webhook_deliveries') === 3
    && $countIn("SELECT COUNT(*) FROM request_logs WHERE request_id = ?", [$reqPrune]) === 1204
    && $countIn('SELECT COUNT(*) FROM admin_audit_log WHERE entity_name LIKE ?', ["rtk{$run}%"]) === 2);
$check('no result file is touched by the default pass',
    is_file($fileOfExecFile) && is_file($tmpSibling) && is_file($execYoungCallFile));

// ------------------- phase 2: the same pass with windows ---------------------
putenv('GATEWAY_RETENTION_REQUEST_LOGS_DAYS=7');
putenv('GATEWAY_RETENTION_CALLS_DAYS=30');
putenv('GATEWAY_RETENTION_EXECUTIONS_DAYS=45');
putenv('GATEWAY_RETENTION_DELIVERIES_DAYS=10');
putenv('GATEWAY_RETENTION_AUDIT_DAYS=7');

$tmp = Filesystem::storageDir() . "/_rtk_{$run}_stderr.tmp";
$proc = proc_open('php tests/retention_test.php --prune',
    [1 => ['pipe', 'w'], 2 => ['file', $tmp, 'w']], $pipes, BASE_PATH);
if (!is_resource($proc)) {
    $check('prune child spawns', false, 'proc_open failed');
    $s1 = [];
} else {
    $out = (string)(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    $code = (int)proc_close($proc);
    $err = (string)@file_get_contents($tmp);
    @unlink($tmp);
    $check('prune child exits clean', $code === 0 && $err === '', substr($out . '||' . $err, 0, 300));
    $report = (array)json_decode(trim($out), true);
    $s1 = (array)($report['summary'] ?? []);
}

$check('summary reports what went away', ($s1['logs_removed'] ?? 0) >= 1202
    && ($s1['calls_removed'] ?? 0) === 3 && ($s1['executions_removed'] ?? 0) === 3
    && ($s1['deliveries_removed'] ?? 0) === 1 && ($s1['audit_removed'] ?? 0) === 1,
    json_encode($report ?? []));

$check('old logs pruned in several batches, recent kept',
    $countIn("SELECT COUNT(*) FROM request_logs WHERE request_id = ? AND `timestamp` < ?",
        [$reqPrune, $ago(7)]) === 0
    && $countIn("SELECT COUNT(*) FROM request_logs WHERE request_id = ? AND `timestamp` >= ?",
        [$reqPrune, $ago(7)]) === 2);
$check('old audit row pruned, recent kept',
    $countIn("SELECT COUNT(*) FROM admin_audit_log WHERE entity_name = ?", ["rtk{$run} audit old"]) === 0
    && $countIn("SELECT COUNT(*) FROM admin_audit_log WHERE entity_name = ?", ["rtk{$run} audit new"]) === 1);

$check('old execution and its result file go together',
    $countIn('SELECT COUNT(*) FROM executions WHERE id = ?', [$execFile]) === 0
    && !is_file($fileOfExecFile));
$check('old expired row goes with its .tmp leftover',
    $countIn('SELECT COUNT(*) FROM executions WHERE id = ?', [$execTmp]) === 0
    && !is_file($tmpSibling));
$check('a scheduled row is never pruned',
    $countIn('SELECT COUNT(*) FROM executions WHERE id = ?', [$execScheduled]) === 1);
$check('an execution outlives its window while a call points at it',
    $countIn('SELECT COUNT(*) FROM executions WHERE id = ?', [$execYoungCall]) === 1
    && is_file($execYoungCallFile));
$check('the call-less execution goes after its call',
    $countIn('SELECT COUNT(*) FROM executions WHERE id = ?', [$execOldCall]) === 0
    && $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callOnOldExec]) === 0);

$check('old call without a delivery is gone', $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callPlain]) === 0);
$check('a pending delivery keeps its call', $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callPending]) === 1
    && $countIn('SELECT COUNT(*) FROM webhook_deliveries WHERE id = ?', [$deliveryPending]) === 1,
    'call=' . $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callPending])
    . ' delivery=' . $countIn('SELECT COUNT(*) FROM webhook_deliveries WHERE id = ?', [$deliveryPending])
    . ' state=' . (string)$db->value('SELECT state FROM webhook_deliveries WHERE call_id = ?', [$callPending]));
$check('a settled delivery the window missed goes with its call',
    $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callYoungDelivery]) === 0
    && $countIn('SELECT COUNT(*) FROM webhook_deliveries WHERE id = ?', [$deliveryYoung]) === 0);
$check('recent call kept', $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callYoung]) === 1);
$check('deliveries window prunes a settled row without losing its call',
    $countIn('SELECT COUNT(*) FROM webhook_deliveries WHERE id = ?', [$deliveryOfYoungCall]) === 0
    && $countIn('SELECT COUNT(*) FROM calls WHERE id = ?', [$callYoungDelivery2]) === 1);

// ------------------- phase 3: the free-space floor ---------------------------
$free = Filesystem::freeBytes();
$min = (int)Config::get('limits.min_free_disk_bytes', 0);
$check('guard agrees with the numbers it reads',
    (StorageGuard::reason() === '') === ($min <= 0 || $free < 0 || $free >= $min),
    "free={$free} min={$min}");

// A threshold above the real free space must stop the worker before it writes.
$db->execute(
    'INSERT INTO executions (request_id, mode, status, scheduled_at) VALUES (?, ?, ?, ?)',
    [$reqDisk, 'delayed', 'scheduled', $now]
);
$execDisk = $db->insertId();
$threshold = ($free > 0 ? $free : 1024 * 1024 * 1024) + 1024 * 1024 * 1024;
$env = getenv();
$env['GATEWAY_MIN_FREE_DISK_BYTES'] = (string)$threshold;
$proc = proc_open('php cli/worker.php', [1 => ['pipe', 'w'], 2 => ['file', $tmp, 'w']], $pipes, BASE_PATH, $env);
if (!is_resource($proc)) {
    $check('worker child spawns', false, 'proc_open failed');
} else {
    $out = (string)(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    $code = (int)proc_close($proc);
    $err = (string)@file_get_contents($tmp);
    @unlink($tmp);
    $check('worker exits with the floor raised', $code === 0 && $err === '', substr($out . '||' . $err, 0, 300));
}
$eDisk = $db->one('SELECT * FROM executions WHERE id = ?', [$execDisk]) ?? [];
$check('storage_low fails the run', (string)($eDisk['status'] ?? '') === 'failed'
    && (string)($eDisk['error_code'] ?? '') === 'storage_low'
    && str_contains((string)($eDisk['error_message'] ?? ''), 'storage_low'),
    substr((string)($eDisk['error_message'] ?? 'null'), 0, 160));
$check('no file is written when the floor is not met',
    !is_file(Filesystem::resultsDir() . '/' . $execDisk . '.json')
    && !is_file(Filesystem::resultsDir() . '/' . $execDisk . '.json.tmp'));
$check('the failure is logged', $countIn(
    "SELECT COUNT(*) FROM request_logs WHERE execution_id = ? AND event = 'failed'", [$execDisk]) === 1);

echo $failures === 0 ? '=== RETENTION TEST PASS ===' : "=== RETENTION TEST: {$failures} FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
