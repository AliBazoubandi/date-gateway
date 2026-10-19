<?php
// ============================================================================
// Integration test: the worker pool parallelises across connections (P1-1).
//
// Two connections each get one delayed SLEEP(3) execution. The sequential
// worker took ~6 s for that pair and a slow connection blocked every other
// client's delayed work; the pool must run them at the same time (~3 s) and
// must not claim an execution twice on a repeat pass.
//
// Runs only inside the throwaway test database (test_guard).
// Usage: php tests\pool_test.php
// ============================================================================

use Core\Filesystem;
use Core\Str;
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
$run = substr(Str::hex(4), 0, 8);
$connectionIds = [];
$requestIds = [];
$wipe = static function () use ($db, &$connectionIds, &$requestIds): void {
    if ($requestIds !== []) {
        $r = implode(',', array_map('intval', $requestIds));
        foreach ($db->rows("SELECT result_path FROM executions WHERE request_id IN ($r)") as $row) {
            if ($row['result_path'] !== null) {
                Cleanup::safeUnlink((string)$row['result_path']);
            }
        }
        $db->execute("DELETE FROM executions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM request_logs WHERE request_id IN ($r)");
        $db->execute("DELETE FROM requests WHERE id IN ($r)");
    }
    if ($connectionIds !== []) {
        $db->execute('DELETE FROM connections WHERE id IN ('
            . implode(',', array_map('intval', $connectionIds)) . ')');
    }
};
register_shutdown_function($wipe);

$now = now_str();
$mkConn = static function (string $name) use ($db, $now, &$connectionIds): int {
    $db->execute(
        'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted,
            max_concurrency, is_active, created_at)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
        [$name, 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), 2, 1, $now]
    );
    $id = $db->insertId();
    $connectionIds[] = $id;
    return $id;
};
$mkSlowRequest = static function (string $code, int $connId) use ($db, $now, &$requestIds): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
            delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, 15, 30, 1000, 1, ?)',
        [$code, $code, 'SELECT SLEEP(3) AS slow', $connId, 'delayed', $now]
    );
    $id = $db->insertId();
    $requestIds[] = $id;
    return $id;
};
$mkExec = static function (int $requestId) use ($db, $now): int {
    $db->execute(
        "INSERT INTO executions (request_id, mode, status, scheduled_at) VALUES (?, 'delayed', 'scheduled', ?)",
        [$requestId, $now]
    );
    return $db->insertId();
};

$connA = $mkConn("pool {$run} A");
$connB = $mkConn("pool {$run} B");
$reqA = $mkSlowRequest("plk{$run}.A", $connA);
$reqB = $mkSlowRequest("plk{$run}.B", $connB);
$execA = $mkExec($reqA);
$execB = $mkExec($reqB);

// Run a real CLI entry point: [exit code, combined stdout+stderr, seconds].
$runCli = static function (string $relArgs) use ($run): array {
    $tmp = Filesystem::storageDir() . "/_plk_{$run}_stderr.tmp";
    $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $tmp, 'w']];
    $t0 = microtime(true);
    $proc = proc_open('php ' . $relArgs, $descriptors, $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        return [-1, 'spawn failed', 0.0];
    }
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $code = (int)proc_close($proc);
    $err = @file_get_contents($tmp);
    @unlink($tmp);
    return [$code, $out . ($err === false || $err === '' ? '' : ' ||ERR:: ' . trim($err)),
        microtime(true) - $t0];
};

// ======================= one pool pass over two connections =================
[$code, $out, $elapsed] = $runCli('cli/worker_pool.php');
$check('pool exits 0', $code === 0 && !str_contains($out, 'ERR::'), substr($out, 0, 400));

$status = static fn (int $id): string => (string)($db->one('SELECT status FROM executions WHERE id = ?', [$id])['status'] ?? '-');
$check('connection A execution ready', $status($execA) === 'ready', $status($execA));
$check('connection B execution ready', $status($execB) === 'ready', $status($execB));

// Two SLEEP(3) queries in parallel ~3 s; the old sequential pass needed ~6 s.
$check('the two connections ran concurrently, not back to back',
    $elapsed < 5.0, sprintf('%.2fs for two SLEEP(3) (sequential would be ~6s)', $elapsed));
$check('pool spawned one child per connection',
    substr_count($out, '[worker:') === 2, preg_replace('/\s+/', ' ', substr($out, 0, 300)));
$check('pool ran housekeeping once, not per child',
    substr_count($out, '[pool] stale-cleaned=') === 1);
$check('pool drained webhooks once', substr_count($out, '[pool] webhooks') === 1);

// ======================= repeat pass: nothing double-claimed ================
[$code2, $out2] = $runCli('cli/worker_pool.php');
$check('repeat pool pass is a no-op', $code2 === 0 && str_contains($out2, 'due=0'), substr($out2, 0, 300));
$readyCount = (int)$db->value(
    "SELECT COUNT(*) FROM executions WHERE id IN (?, ?) AND status = 'ready'",
    [$execA, $execB]
);
$check('executions still ready exactly once each', $readyCount === 2);

// A finished result file exists for each and is valid.
foreach ([['exec' => $execA, 'conn' => $connA], ['exec' => $execB, 'conn' => $connB]] as $c) {
    $row = $db->one('SELECT result_path, row_count FROM executions WHERE id = ?', [$c['exec']]);
    $file = Filesystem::resultsDir() . '/' . $c['exec'] . '.json';
    $check('result file written for execution ' . $c['exec'], is_file($file) && $row['result_path'] !== null);
    $decoded = json_decode((string)@file_get_contents($file), true);
    $check('result file is complete JSON', is_array($decoded) && isset($decoded['data']),
        (string)json_last_error_msg());
}

// ======================= a slow connection does not block another ===========
// Same connection, two slow executions: the per-connection lock means the
// second child skips rather than queueing behind a duplicate claim.
$execA2 = $mkExec($reqA);
[$code3, $out3] = $runCli('cli/worker.php --connection=' . $connB);
$check('single-connection child only claims its own connection',
    $code3 === 0 && str_contains($out3, "[worker:{$connB}]"), substr($out3, 0, 200));
$check('other connection untouched by that child',
    $status($execA2) === 'scheduled', $status($execA2));

echo $failures === 0 ? '=== POOL TEST PASS ===' : "=== POOL TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
