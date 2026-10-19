<?php
// End-to-end smoke test for the CLI worker + cleanup (build step 4).
// Seeds delayed executions directly in the metadata DB, runs the REAL CLI
// entry points (php cli\worker.php and php cli\cleanup.php via proc_open)
// and asserts: ready flow (streamed file envelope + token + TTL), webhook
// enqueue per attached call, query failure + timeout classification,
// truncation, per-connection cap block, future scheduled_at, inactive
// sweep, stale 'running' recovery, then the cleanup pass (expiry, file
// removal, failed leftovers, stale instant recovery).
// Usage: php tests\worker_smoke.php

use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Encryption;
use Worker\Cleanup;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();
require __DIR__ . '/../src/Core/Filesystem.php';

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label,
        $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$db = db();

// ------------------- per-run fixtures (unique names, self-cleaning) -------------------
// Every fixture name carries the random run id; the shutdown cleanup removes
// exactly this run's rows/files (FK-safe order), so a failed run leaves
// nothing behind and real data is never matched.
$run = substr(Str::hex(4), 0, 8);
$fixtureFileRels = [];
$connectionIds = [];
$requestIds = [];
$clientIds = [];
$wipe = static function () use ($db, &$fixtureFileRels, &$connectionIds, &$requestIds, &$clientIds): void {
    foreach ($fixtureFileRels as $rel) {
        Cleanup::safeUnlink($rel);
    }
    if ($requestIds !== []) {
        $r = implode(',', array_map('intval', $requestIds));
        foreach ($db->rows("SELECT result_path FROM executions WHERE request_id IN ($r)") as $row) {
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
    if ($connectionIds !== []) {
        $db->execute('DELETE FROM connections WHERE id IN (' . implode(',', array_map('intval', $connectionIds)) . ')');
    }
};
register_shutdown_function($wipe);

$now = now_str();
$mkConn = static function (string $name, int $cap, int $active) use ($db, $now): int {
    $db->execute(
        'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted, max_concurrency, is_active, created_at)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
        [$name, 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), $cap, $active, $now]
    );
    return $db->insertId();
};
$connMain = $mkConn("wsmk {$run} main", 2, 1);
$connCap = $mkConn("wsmk {$run} cap1", 1, 1);
$connOff = $mkConn("wsmk {$run} offline", 2, 0);
array_push($connectionIds, $connMain, $connCap, $connOff);

$mkRequest = static function (string $code, string $sql, int $connId, int $ttl = 15,
    int $timeout = 10, int $maxRows = 1000) use ($db, $now): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
            delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 1, ?)',
        [$code, $code, $sql, $connId, 'delayed', $ttl, $timeout, $maxRows, $now]
    );
    return $db->insertId();
};

$rReady = $mkRequest("swk{$run}.ready", 'SELECT 1 AS ping UNION ALL SELECT 2 AS ping', $connMain);
$rTtl = $mkRequest("swk{$run}.ttl", 'SELECT 1 AS ping', $connMain, 3);
$rBad = $mkRequest("swk{$run}.bad", 'SELECT * FROM _definitely_not_here', $connMain);
$rTrunc = $mkRequest("swk{$run}.trunc", 'SELECT 1 AS n UNION ALL SELECT 2 AS n UNION ALL SELECT 3 AS n', $connMain, 5, 10, 2);
$rCap = $mkRequest("swk{$run}.cap", 'SELECT 1 AS ping', $connCap);
$rInactive = $mkRequest("swk{$run}.inactive", 'SELECT 1 AS ping', $connOff);
$rFuture = $mkRequest("swk{$run}.future", 'SELECT 1 AS ping', $connMain);
$rSlow = $mkRequest("swk{$run}.slow", 'SELECT SLEEP(4) AS slow', $connMain, 15, 1);
array_push($requestIds, $rReady, $rTtl, $rBad, $rTrunc, $rCap, $rInactive, $rFuture, $rSlow);

$mkExec = static function (array $fields) use ($db): int {
    $row = array_merge([
        'mode' => 'delayed',
        'status' => 'scheduled',
        'scheduled_at' => now_str(),
    ], $fields);
    $cols = implode(', ', array_keys($row));
    $ph = implode(', ', array_fill(0, count($row), '?'));
    $db->execute("INSERT INTO executions ({$cols}) VALUES ({$ph})", array_values($row));
    return $db->insertId();
};

$due = now_str();
$future = Time::mysql(Time::addMinutes(new \DateTimeImmutable($due, Time::timezone()), 10));
$staleStarted = Time::mysql(Time::addMinutes(new \DateTimeImmutable($due, Time::timezone()), -120));

$execReady = $mkExec(['request_id' => $rReady, 'scheduled_at' => $due]);
$execTtl = $mkExec(['request_id' => $rTtl, 'scheduled_at' => $due]);
$execBad = $mkExec(['request_id' => $rBad, 'scheduled_at' => $due]);
$execTrunc = $mkExec(['request_id' => $rTrunc, 'scheduled_at' => $due]);
$execWebhook = $mkExec(['request_id' => $rReady, 'scheduled_at' => $due]);
$execSlow = $mkExec(['request_id' => $rSlow, 'scheduled_at' => $due]);
$execCap = $mkExec(['request_id' => $rCap, 'scheduled_at' => $due]);
$execFuture = $mkExec(['request_id' => $rFuture, 'scheduled_at' => $future]);
$execInactive = $mkExec(['request_id' => $rInactive, 'scheduled_at' => $due]);

// Fresh 'running' leftover: spared by stale recovery, and it must block a
// cap=1 connection from claiming anything new.
$execFreshRunning = $mkExec(['request_id' => $rCap, 'status' => 'running', 'started_at' => $due]);
// Crashed-worker leftover: old 'running' row with a partial file to purge.
$staleFileRel = "results/wsmk_{$run}_stale.json";
file_put_contents(Filesystem::absoluteFromRelative($staleFileRel), '{"data":[');
$fixtureFileRels[] = $staleFileRel;
$execStale = $mkExec(['request_id' => $rFuture, 'status' => 'running',
    'started_at' => $staleStarted, 'result_path' => $staleFileRel]);

// A client + one call attached to an execution -> webhook delivery expected.
$db->execute(
    'INSERT INTO clients (name, type, api_key_hash, is_active, created_at) VALUES (?, ?, ?, 1, ?)',
    ["wsmk {$run} client", 'agent', hash('sha256', Str::hex(16)), $now]
);
$clientId = $db->insertId();
$clientIds[] = $clientId;
$webhookCallId = Str::hex(16);
$db->execute(
    'INSERT INTO calls (id, client_id, request_id, execution_id, created_at, ip, was_shared)
     VALUES (?, ?, ?, ?, ?, ?, 0)',
    [$webhookCallId, $clientId, $rReady, $execWebhook, $now, 'cli-test']
);
// A second call attached to the FAILING execution: the worker must enqueue its
// delivery too, so the client is notified instead of polling forever.
$failCallId = Str::hex(16);
$db->execute(
    'INSERT INTO calls (id, client_id, request_id, execution_id, created_at, ip, was_shared)
     VALUES (?, ?, ?, ?, ?, ?, 0)',
    [$failCallId, $clientId, $rBad, $execBad, $now, 'cli-test']
);

$logCount = static fn (string $event, int $execId): int => (int)$db->value(
    'SELECT COUNT(*) FROM request_logs WHERE event = ? AND execution_id = ?',
    [$event, $execId]
);
$ttlDiff = static function (array $e): ?int {
    if (empty($e['expires_at']) || empty($e['ready_at'])) {
        return null;
    }
    return Time::fromSql((string)$e['expires_at'])->getTimestamp()
        - Time::fromSql((string)$e['ready_at'])->getTimestamp();
};

// Run a real CLI entry point: [exit code, combined stdout+stderr].
$runCli = static function (string $relScript) use ($run): array {
    $tmp = Filesystem::storageDir() . "/_wsmk_{$run}_stderr.tmp";
    $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $tmp, 'w']];
    $proc = proc_open('php ' . $relScript, $descriptors, $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        return [-1, 'spawn failed'];
    }
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $code = (int)proc_close($proc);
    $err = @file_get_contents($tmp);
    @unlink($tmp);
    return [$code, $out . ($err === false || $err === '' ? '' : ' ||ERR:: ' . trim($err))];
};

// ======================= pass 1: the worker CLI =============================
[$wCode, $wOut] = $runCli('cli/worker.php');
$check('CLI worker exits 0', $wCode === 0 && !str_contains($wOut, 'ERR::'), substr($wOut, 0, 300));
$execRow = static fn (int $id): array => $db->one('SELECT * FROM executions WHERE id = ?', [$id]) ?? [];

$e = $execRow($execReady);
$check('ready: execution ready', $e['status'] === 'ready' && $e['started_at'] !== null
    && $e['finished_at'] !== null, (string)$e['status']);
$check('ready: result_path + stored size', (string)$e['result_path'] === 'results/' . $execReady . '.json'
    && (int)$e['result_size_bytes'] === (int)(@filesize(Filesystem::resultsDir() . '/' . $execReady . '.json') ?: -1),
    (string)$e['result_path']);
$check('ready: token hash 64-hex, raw token stored nowhere',
    preg_match('/^[0-9a-f]{64}$/', (string)$e['download_token_hash']) === 1
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE download_token_hash = ?', [$e['download_token_hash']]) === 1);
$rel = Filesystem::absoluteFromRelative((string)$e['result_path']);
$decoded = is_file($rel) ? json_decode((string)file_get_contents($rel), true) : null;
$check('ready: streamed data rows', is_array($decoded)
    && ($decoded['data'] ?? null) === [['ping' => '1'], ['ping' => '2']], substr(json_encode($decoded) ?: 'null', 0, 120));
$check('ready: envelope meta', is_array($decoded)
    && ($decoded['meta']['row_count'] ?? -1) === 2 && ($decoded['meta']['truncated'] ?? true) === false
    && ($decoded['meta']['execution_id'] ?? 0) === $execReady
    && ($decoded['meta']['request_code'] ?? '') === "swk{$run}.ready");
$check('ready: logs started + succeeded',
    $logCount('started', $execReady) === 1 && $logCount('succeeded', $execReady) === 1);
$check('ready: expires = ready_at + ttl 15min', $ttlDiff($e) !== null
    && abs($ttlDiff($e) - 900) <= 2, (string)($e['expires_at'] ?? 'null'));

$check('ttl3: expires = ready_at + 3min', $ttlDiff($execRow($execTtl)) !== null
    && abs($ttlDiff($execRow($execTtl)) - 180) <= 2);

$check('webhook: pending delivery row for the attached call',
    (string)$db->value('SELECT state FROM webhook_deliveries WHERE call_id = ?', [$webhookCallId]) === 'pending');
$check('webhook: failed execution also enqueues its attached call',
    (string)$db->value('SELECT state FROM webhook_deliveries WHERE call_id = ?', [$failCallId]) === 'pending');
$check('webhook: nothing enqueued without attached calls',
    (int)$db->value('SELECT COUNT(*) FROM webhook_deliveries') === 2,
    'deliveries=' . (int)$db->value('SELECT COUNT(*) FROM webhook_deliveries'));

$e = $execRow($execBad);
$check('bad sql: failed with recorded error, no file', $e['status'] === 'failed'
    && ($e['error_message'] ?? '') !== '' && $e['result_path'] === null,
    (string)($e['error_message'] ?? 'null'));
$check('bad sql: sanitized error code', (string)$e['error_code'] === 'execution_failed',
    (string)($e['error_code'] ?? 'null'));
$check('bad sql: no stray file', !is_file(Filesystem::resultsDir() . '/' . $execBad . '.json'));
$check('bad sql: failed logged', $logCount('failed', $execBad) === 1);

$check('timeout: failed', $execRow($execSlow)['status'] === 'failed');
$check('timeout: sanitized error code', (string)$execRow($execSlow)['error_code'] === 'timeout',
    (string)($execRow($execSlow)['error_code'] ?? 'null'));
$check('timeout: classified as timeout event', $logCount('timeout', $execSlow) === 1);

$e = $execRow($execTrunc);
$truncPath = Filesystem::resultsDir() . '/' . $execTrunc . '.json';
$decoded = is_file($truncPath) ? json_decode((string)file_get_contents($truncPath), true) : null;
$check('trunc: row_count 2 + truncated flag', $e['status'] === 'ready'
    && (int)$e['row_count'] === 2 && (int)$e['truncated'] === 1, (string)$e['row_count']);
$check('trunc: file holds exactly the streamed rows + meta flag', is_array($decoded)
    && count($decoded['data'] ?? []) === 2 && ($decoded['meta']['truncated'] ?? false) === true);
$check('trunc: success log mentions truncation',
    str_contains((string)$db->value("SELECT message FROM request_logs WHERE event = 'succeeded' AND execution_id = ?", [$execTrunc]), 'truncated'));

$check('cap: fresh running row spared by recovery',
    $execRow($execFreshRunning)['status'] === 'running');
$check('cap: cap=1 connection blocked new claims', $execRow($execCap)['status'] === 'scheduled'
    && empty($execRow($execCap)['started_at']), 'running at claim time on that connection: '
    . (string)$db->value("SELECT COUNT(*) FROM executions e JOIN requests r ON r.id = e.request_id
        WHERE e.mode = 'delayed' AND e.status = 'running' AND r.connection_id = ?", [$connCap]));
$check('future: stays scheduled and untouched', $execRow($execFuture)['status'] === 'scheduled'
    && empty($execRow($execFuture)['started_at']));
$e = $execRow($execInactive);
$check('inactive: swept to failed', $e['status'] === 'failed'
    && str_contains((string)$e['error_message'], 'inactive'), (string)$e['error_message']);
$check('inactive: sanitized error code', (string)$e['error_code'] === 'execution_failed',
    (string)($e['error_code'] ?? 'null'));
$e = $execRow($execStale);
$check('stale: recovered to failed', $e['status'] === 'failed'
    && str_contains((string)$e['error_message'], 'interrupted'), (string)$e['error_message']);
$check('stale: partial file deleted + path cleared',
    !is_file(Filesystem::absoluteFromRelative($staleFileRel)) && $e['result_path'] === null);
$check('stale: failure logged', $logCount('failed', $execStale) === 1);
$check('summary line: due=7 ready=4 failed=2 blocked=1 + swept counts',
    str_contains($wOut, 'due=7') && str_contains($wOut, 'ready=4')
    && str_contains($wOut, 'failed=2') && str_contains($wOut, 'blocked=1')
    && str_contains($wOut, 'stale-cleaned=1') && str_contains($wOut, 'inactive-cleaned=1'),
    substr($wOut, 0, 200));

// ======================= pass 2: the cleanup CLI =============================
// Mark two ready executions expired; seed file leftovers + a stale instant.
$cacheStaleRel = "cache/wsmk_{$run}_cache.json";
file_put_contents(Filesystem::absoluteFromRelative($cacheStaleRel), '[{"ping":1}]');
$cacheFreshRel = "cache/wsmk_{$run}_fresh.json";
file_put_contents(Filesystem::absoluteFromRelative($cacheFreshRel), '[{"ping":9}]');
$failedFileRel = "results/wsmk_{$run}_failed.json";
file_put_contents(Filesystem::absoluteFromRelative($failedFileRel), '{"data":[1]');
array_push($fixtureFileRels, $cacheStaleRel, $cacheFreshRel, $failedFileRel);

$execCacheStale = $mkExec(['request_id' => $rReady, 'mode' => 'instant', 'status' => 'ready',
    'expires_at' => Time::mysql(Time::addMinutes(new \DateTimeImmutable($due, Time::timezone()), -1)),
    'result_path' => $cacheStaleRel]);
$execCacheFresh = $mkExec(['request_id' => $rReady, 'mode' => 'instant', 'status' => 'ready',
    'expires_at' => Time::mysql(Time::addMinutes(new \DateTimeImmutable($due, Time::timezone()), 5)),
    'result_path' => $cacheFreshRel]);
$execFailedFile = $mkExec(['request_id' => $rBad, 'status' => 'failed',
    'result_path' => $failedFileRel]);
$execStaleInst = $mkExec(['request_id' => $rReady, 'mode' => 'instant', 'status' => 'running',
    'started_at' => Time::mysql(Time::addMinutes(new \DateTimeImmutable($due, Time::timezone()), -10))]);

$db->execute("UPDATE executions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id IN (?, ?)",
    [$execReady, $execTtl]);

[$cCode, $cOut] = $runCli('cli/cleanup.php');
$check('CLI cleanup exits 0', $cCode === 0 && !str_contains($cOut, 'ERR::'), substr($cOut, 0, 300));
$check('cleanup: expired ready marked', $execRow($execReady)['status'] === 'expired'
    && $execRow($execTtl)['status'] === 'expired');
$check('cleanup: their files deleted',
    !is_file(Filesystem::resultsDir() . '/' . $execReady . '.json')
    && !is_file(Filesystem::resultsDir() . '/' . $execTtl . '.json'));
$check('cleanup: expiry logged', $logCount('expired', $execReady) === 1);
$check('cleanup: stale cache file removed + marked',
    !is_file(Filesystem::absoluteFromRelative($cacheStaleRel))
    && $execRow($execCacheStale)['status'] === 'expired');
$check('cleanup: fresh cache file untouched', is_file(Filesystem::absoluteFromRelative($cacheFreshRel))
    && $execRow($execCacheFresh)['status'] === 'ready');
$check('cleanup: failed leftover file cleared',
    !is_file(Filesystem::absoluteFromRelative($failedFileRel))
    && $execRow($execFailedFile)['result_path'] === null);
$check('cleanup: stale instant running -> failed', $execRow($execStaleInst)['status'] === 'failed');
$check('cleanup: still-ready execution untouched', $execRow($execTrunc)['status'] === 'ready'
    && is_file($truncPath));
$check('cleanup summary printed', str_contains($cOut, 'expired-marked=') && str_contains($cOut, 'stale-instant-cleaned='),
    substr($cOut, 0, 160));

// --------------------------- final self-clean -------------------------------
foreach ([
    Filesystem::resultsDir() . '/' . $execWebhook . '.json',
    $truncPath,
    Filesystem::absoluteFromRelative($cacheFreshRel),
    Filesystem::absoluteFromRelative($staleFileRel),
    Filesystem::absoluteFromRelative($failedFileRel),
] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
$wipe();
$check('fixtures removed', (int)$db->value("SELECT COUNT(*) FROM requests WHERE code LIKE 'swk{$run}%'") === 0
    && (int)$db->value("SELECT COUNT(*) FROM connections WHERE name LIKE 'wsmk {$run}%'") === 0
    && (int)$db->value('SELECT COUNT(*) FROM clients WHERE name LIKE ?', ["wsmk {$run}%"]) === 0);

echo $failures === 0 ? '=== WORKER SMOKE PASS ===' : "=== WORKER SMOKE: {$failures} FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
