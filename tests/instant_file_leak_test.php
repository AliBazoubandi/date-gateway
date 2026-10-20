<?php
// ============================================================================
// tests/instant_file_leak_test.php - BUG 4: a failed instant result FILE must
// not leave its temp file behind.
//
// An instant run streams rows into storage/results/{id}.json.tmp and publishes
// the file at {id}.json only when the query succeeded. If the file writer gives
// up (byte cap, disk error) the inline response is still served - that is the
// instant contract - but the open temp file used to stay on disk forever:
// sweepOrphanFiles() ignores it (the executions row exists) and
// clearLeftoverFiles() only looks at rows whose result_path is not NULL.
//
// The cap is forced per-run: the child `php -S` this test starts gets
// GATEWAY_MAX_RESULT_BYTES=10, so the very first row overflows the FILE cap
// while the inline cap stays generous and the query itself succeeds.
//
// Usage: php tests/instant_file_leak_test.php   (needs the throwaway
//        environment: GATEWAY_TEST_MODE=1 + a *_test schema, via run_all.php)
// ============================================================================

declare(strict_types=1);

use Core\Filesystem;
use Core\Str;
use Services\Encryption;
use Worker\Cleanup;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

$db = db();
Filesystem::prepare();

$failures = 0;
$total = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures, &$total): void {
    $total++;
    echo str_pad($ok ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

// ---- per-run fixtures, self-cleaning ---------------------------------------
$run = substr(Str::hex(4), 0, 8);
$now = now_str();
$code = "leak{$run}.instant";
$connectionIds = [];
$requestIds = [];
$clientIds = [];
$resultFiles = [];

$wipe = static function () use ($db, &$connectionIds, &$requestIds, &$clientIds, &$resultFiles): void {
    foreach ($resultFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    if ($requestIds !== []) {
        $r = implode(',', array_map('intval', $requestIds));
        $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE request_id IN ($r))");
        $db->execute("DELETE FROM calls WHERE request_id IN ($r)");
        $db->execute("DELETE FROM executions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM client_request_permissions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM request_logs WHERE request_id IN ($r)");
        $db->execute("DELETE FROM requests WHERE id IN ($r)");
    }
    if ($clientIds !== []) {
        $c = implode(',', array_map('intval', $clientIds));
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

$db->execute(
    'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted,
            max_concurrency, is_active, created_at)
     VALUES (?, ?, ?, NULL, ?, ?, ?, 2, 1, ?)',
    ["leak {$run} target", 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), $now]
);
$connId = $db->insertId();
$connectionIds[] = $connId;

$db->execute(
    'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
            delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
     VALUES (?, ?, ?, ?, ?, 0, 0, 0, 10, 100, 1, ?)',
    // ~60 bytes of JSON for one row: past a 10-byte file cap, far below the
    // inline cap. The query itself is perfectly fine.
    [$code, $code, "SELECT REPEAT('x', 50) AS pad", $connId, 'instant', $now]
);
$reqId = $db->insertId();
$requestIds[] = $reqId;

$key = Str::hex(24);
$db->execute(
    'INSERT INTO clients (name, type, api_key_hash, is_active, created_at) VALUES (?, ?, ?, 1, ?)',
    ["leak {$run} client", 'agent', hash('sha256', $key), $now]
);
$clientId = $db->insertId();
$clientIds[] = $clientId;
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)',
    [$clientId, $reqId, $now]);

// ---- a dev server whose FILE cap is 10 bytes -------------------------------
$servers = [];
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $proc) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
});
$env = [];
foreach ((array)getenv() as $name => $value) {
    $env[(string)$name] = (string)$value;
}
$env['GATEWAY_MAX_RESULT_BYTES'] = '10';
$env['GATEWAY_INSTANT_MAX_BYTES'] = '1048576';
$port = rand(20000, 60000);
$pipes = [];
$proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', 'public', 'tests/dev_router.php'],
    [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes, BASE_PATH, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, 'cannot start php -S' . PHP_EOL);
    exit(2);
}
$servers[] = $proc;
$ready = false;
for ($i = 0; $i < 60; $i++) {
    usleep(100000);
    $probe = curl_init('http://127.0.0.1:' . $port . '/health');
    curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5]);
    curl_exec($probe);
    $ready = (int)curl_getinfo($probe, CURLINFO_HTTP_CODE) !== 0;
    curl_close($probe);
    if ($ready) {
        break;
    }
}
if (!$ready) {
    fwrite(STDERR, 'php -S never answered' . PHP_EOL);
    exit(2);
}

// ---- the submit -------------------------------------------------------------
$ch = curl_init('http://127.0.0.1:' . $port . '/api/v1/request/' . $code);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
]);
$body = (string)curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$payload = json_decode($body, true);

$check('the query still answers 200 with inline data', $httpCode === 200
    && is_array($payload) && ($payload['status'] ?? '') === 'ready'
    && strlen((string)($payload['data'][0]['pad'] ?? '')) === 50, $httpCode . ' ' . substr($body, 0, 200));
$check('the failed file shows up as no download link',
    array_key_exists('link', (array)$payload) && $payload['link'] === null);

$exec = $db->one("SELECT * FROM executions WHERE request_id = ? ORDER BY id DESC LIMIT 1", [$reqId]);
$execId = $exec === null ? 0 : (int)$exec['id'];
$check('the execution is ready and carried no result path', $exec !== null
    && (string)$exec['status'] === 'ready' && $exec['result_path'] === null, json_encode($exec));

// The regression: the abandoned {id}.json.tmp must be gone when the request
// ends, not left for a sweep that cannot see it.
$resultsDir = Filesystem::resultsDir();
$leftovers = glob($resultsDir . '/*.tmp') ?: [];
$check('no temp result file is left behind', $leftovers === [], implode(', ', $leftovers));
$check('nothing under that execution id exists at all',
    glob($resultsDir . '/' . $execId . '.*') === [] && glob($resultsDir . '/' . $execId . '.json*') === [],
    implode(', ', (array)glob($resultsDir . '/' . $execId . '.*')));

// A cleanup pass cannot reclaim it either (that is why the explicit discard is
// the fix), so it must have nothing to reclaim.
Cleanup::run();
$check('a cleanup pass also finds nothing for that execution',
    glob($resultsDir . '/' . $execId . '.*') === [], implode(', ', (array)glob($resultsDir . '/' . $execId . '.*')));

echo PHP_EOL, '=== instant_file_leak_test: ', $failures === 0
    ? "ALL {$total} CHECKS PASSED ==="
    : "{$failures} of {$total} CHECKS FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
