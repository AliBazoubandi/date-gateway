<?php
// End-to-end smoke test for the JSON API (build step 3).
// Bootstraps its own fixtures (connection, requests, clients, permissions),
// then drives /api/v1/* over php -S with curl, asserting:
//   auth failures (401/403/404 + 'denied' logs), delayed cache rule 1 (share),
//   status polling (owner-only), rule 2 attach with fresh call token,
//   chunked download, 410 expiry, instant run + result file + link +
//   ?download=1 file delivery / cache hit / 504 / 502 / 429, and the
//   Idempotency-Key replay rules (scheduled/ready/expired/failed, per-client
//   and per-request scoping, key validation).
// Usage: php tests\api_smoke.php

use Services\Encryption;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();
require __DIR__ . '/../src/Core/Filesystem.php';

// ----------------- per-run fixtures (unique names, self-cleaning) -----------------
// run_all.ps1 points the gateway at a throwaway DB + storage dir (see
// test_guard). Every fixture name carries the random run id, and the
// registered shutdown cleanup removes exactly this run's rows and files —
// a failed run leaves nothing behind and real data is never matched.
$run = substr(\Core\Str::hex(4), 0, 8);
$codeDelayed = "smk{$run}.delayed";
$codeDeny = "smk{$run}.delayed_deny";
$codeInst = "smk{$run}.instant";
$codeBad = "smk{$run}.instant_bad";
$codeSlow = "smk{$run}.instant_slow";
$codeRl = "smk{$run}.instant_rl";
$codeInstDl = "smk{$run}.instant_dl";
$codeNone = "smk{$run}.none";
$codeBig = "smk{$run}.instant_big";
$codeCacheMiss = "smk{$run}.instant_cachemiss";
$codeClamp = "smk{$run}.instant_clamp";

$codeIdemInst = "smk{$run}.idem_instant";
$codeIdemDelay = "smk{$run}.idem_delayed";

$db = db();
$clientIds = [];
$requestIds = [];
$connectionIds = [];
$extraFiles = [];
// FK-safe order: files -> deliveries -> calls -> executions -> permissions
// -> logs -> requests -> connections -> clients.
$cleanup = static function () use ($db, &$clientIds, &$requestIds, &$connectionIds, &$extraFiles): void {
    $in = static fn (array $ids): string => implode(',', array_map('intval', $ids));
    foreach ($extraFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    if ($requestIds !== []) {
        $r = $in($requestIds);
        foreach ($db->rows("SELECT result_path FROM executions WHERE request_id IN ($r)") as $row) {
            if ($row['result_path'] !== null) {
                \Worker\Cleanup::safeUnlink((string)$row['result_path']);
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
        $c = $in($clientIds);
        $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE client_id IN ($c))");
        $db->execute("DELETE FROM calls WHERE client_id IN ($c)");
        $db->execute("DELETE FROM client_request_permissions WHERE client_id IN ($c)");
        $db->execute("DELETE FROM request_logs WHERE client_id IN ($c)");
        $db->execute("DELETE FROM clients WHERE id IN ($c)");
    }
    if ($connectionIds !== []) {
        $db->execute('DELETE FROM connections WHERE id IN (' . $in($connectionIds) . ')');
    }
};
register_shutdown_function($cleanup);

$now = now_str();
$db->execute(
    'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted, max_concurrency, is_active, created_at)
     VALUES (?, ?, ?, NULL, ?, ?, ?, 2, 1, ?)',
    ["smk {$run} mariadb", 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), $now]
);
$connId = $db->insertId();
$connectionIds[] = $connId;

$addRequest = static function (string $code, string $mode, int $timeout, int $maxRows, int $cache, string $sql) use ($db, $connId, $now): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds, delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, 15, ?, ?, 1, ?)',
        [$code, $code, $sql, $connId, $mode, $cache, $timeout, $maxRows, $now]
    );
    return $db->insertId();
};

$reqDelayed = $addRequest($codeDelayed, 'delayed', 30, 1000, 0, 'SELECT 1 AS ping');
$reqDeny = $addRequest($codeDeny, 'delayed', 30, 1000, 0, 'SELECT 1 AS ping');
$reqInst = $addRequest($codeInst, 'instant', 30, 100, 60, 'SELECT 1 AS ping');
$reqBad = $addRequest($codeBad, 'instant', 30, 100, 0, 'SELECT * FROM _table_that_does_not_exist');
$reqSlow = $addRequest($codeSlow, 'instant', 1, 100, 0, 'SELECT SLEEP(5) AS slow');
$reqRl = $addRequest($codeRl, 'instant', 30, 100, 0, 'SELECT 1 AS ping');
$reqInstDl = $addRequest($codeInstDl, 'instant', 30, 100, 0, 'SELECT 1 AS ping');
$reqBig = $addRequest($codeBig, 'instant', 30, 100, 0, "SELECT REPEAT('x', 5000) AS pad");
$reqCacheMiss = $addRequest($codeCacheMiss, 'instant', 30, 100, 60, 'SELECT 1 AS ping');
// A 24 h window on a request: only the result file lifetime is servable.
$reqClamp = $addRequest($codeClamp, 'instant', 30, 100, 86400, 'SELECT 1 AS ping');
// Idempotency-Key fixtures. Both have caching OFF on purpose: without the
// header every submit must re-run/attach by the normal rules, so a replay can
// only ever come from the key.
$reqIdemInst = $addRequest($codeIdemInst, 'instant', 30, 100, 0, 'SELECT 1 AS ping');
$reqIdemDelay = $addRequest($codeIdemDelay, 'delayed', 30, 1000, 0, 'SELECT 1 AS ping');
array_push($requestIds, $reqDelayed, $reqDeny, $reqInst, $reqBad, $reqSlow, $reqRl, $reqInstDl, $reqBig, $reqCacheMiss, $reqClamp,
    $reqIdemInst, $reqIdemDelay);

$mkClient = static function (string $name, bool $active = true) use ($db, $now): array {
    $key = 'gw_smoketest_' . \Core\Str::hex(16);
    $db->execute(
        'INSERT INTO clients (name, type, api_key_hash, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
        [$name, 'agent', hash('sha256', $key), $active ? 1 : 0, $now]
    );
    $id = $db->insertId();
    return ['id' => $id, 'key' => $key];
};
$A = $mkClient("smk {$run} A");
$B = $mkClient("smk {$run} B");
$C = $mkClient("smk {$run} C (inactive)", false);
array_push($clientIds, (int)$A['id'], (int)$B['id'], (int)$C['id']);
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$A['id'], $reqDelayed, $now]);
$db->execute(
    'INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?)',
    [$A['id'], $reqInst, $now, $A['id'], $reqBad, $now, $A['id'], $reqSlow, $now, $A['id'], $reqInstDl, $now]
);
$db->execute(
    'INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
    [$A['id'], $reqBig, $now, $A['id'], $reqCacheMiss, $now, $A['id'], $reqClamp, $now]
);
$db->execute(
    'INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
    [$A['id'], $reqIdemInst, $now, $A['id'], $reqIdemDelay, $now, $B['id'], $reqIdemInst, $now]
);

// ----------------- dev server + curl harness -----------------
// Random port: a stale or sibling server must never answer instead of us.
$port = rand(20000, 60000);
chdir(BASE_PATH);
// Array command form: no cmd.exe wrapper, so proc_terminate() really kills
// php -S (string form orphans the server, which then wedges later runs).
$proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', 'public', 'tests/dev_router.php'],
    [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "cannot start php -S" . PHP_EOL);
    exit(2);
}
register_shutdown_function(static function () use ($proc): void {
    proc_terminate($proc);
    proc_close($proc);
});
usleep(500000);

function api(string $method, string $path, ?string $key = null, bool $tailHeaders = false, array $extraHeaders = []): array
{
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => $tailHeaders];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = '';
    }
    $httpHeaders = $extraHeaders;
    if ($key !== null) {
        $httpHeaders[] = 'Authorization: Bearer ' . $key;
    }
    if ($httpHeaders !== []) {
        $opts[CURLOPT_HTTPHEADER] = $httpHeaders;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headers = '';
    if ($tailHeaders) {
        $cut = strpos($raw, "\r\n\r\n");
        if ($cut !== false) {
            $headers = substr($raw, 0, $cut);
            $raw = substr($raw, $cut + 4);
        }
    }
    curl_close($ch);
    return [$code, $raw, $type, $headers];
}

$pass = 0;
$total = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$total): void {
    $total++;
    $pass += $ok ? 1 : 0;
    echo str_pad($ok ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
};
$getErr = static function (string $body): string {
    $d = json_decode($body, true);
    return is_array($d) ? (string)($d['error']['code'] ?? '?') : '?';
};

// ----------------- auth failure paths -----------------
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed);
$check('no auth -> 401', $code === 401 && $getErr($body) === 'unauthorized');
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, 'gw_totally_invalid_key_1234567890abc');
$check('invalid key -> 401', $code === 401 && $getErr($body) === 'unauthorized');
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, $C['key']);
$check('inactive client -> 403', $code === 403 && $getErr($body) === 'forbidden');
[$code, $body] = api('POST', '/api/v1/request/' . $codeDeny, $A['key']);
$check('missing permission -> 403', $code === 403 && $getErr($body) === 'forbidden');
$check('missing permission denied log',
    (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE request_id = ? AND event = 'denied'", [$reqDeny]) >= 1);
[$code, $body] = api('POST', '/api/v1/request/' . $codeNone, $A['key']);
$check('unknown code -> 404', $code === 404 && $getErr($body) === 'not_found');
[$code, $body] = api('POST', '/api/v1/request/smk%20bad', $A['key']);
$check('code with illegal chars -> 404', $code === 404 && $getErr($body) === 'not_found');
[$code, $body] = api('GET', '/api/v1/request/' . $codeDelayed, $A['key']);
$check('GET on submit -> 405', $code === 405 && $getErr($body) === 'method_not_allowed');

// ----------------- delayed flow + rule 1 -----------------
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, $A['key']);
$payload = json_decode($body, true);
$check('first submit -> 200 scheduled', $code === 200 && ($payload['status'] ?? '') === 'scheduled'
    && isset($payload['call_id']) && $payload['link'] === null && $payload['expires_at'] === null, $body);
$call1 = (string)$payload['call_id'];
$execRow = $db->one('SELECT * FROM executions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$reqDelayed]);
$check('execution row scheduled', $execRow !== null && $execRow['status'] === 'scheduled'
    && $execRow['scheduled_at'] !== null);
$callRow = $db->one('SELECT * FROM calls WHERE id = ?', [$call1]);
$check('call owns execution, not shared', $callRow !== null
    && (int)$callRow['execution_id'] === (int)$execRow['id'] && (int)$callRow['was_shared'] === 0);

[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, $A['key']);
$payload2 = json_decode($body, true);
$check('second submit attaches (rule 1)', $code === 200 && ($payload2['status'] ?? '') === 'scheduled'
    && ($payload2['call_id'] ?? '') !== $call1);
$callRow2 = $db->one('SELECT * FROM calls WHERE id = ?', [(string)($payload2['call_id'] ?? '')]);
$check('rule-1 call shares the execution',
    $callRow2 !== null && (int)$callRow2['execution_id'] === (int)$execRow['id'] && (int)$callRow2['was_shared'] === 1);

// ----------------- status endpoint -----------------
[$code, $body] = api('GET', '/api/v1/calls/' . $call1, $A['key']);
$st = json_decode($body, true);
$check('status for owner -> scheduled', $code === 200 && ($st['status'] ?? '') === 'scheduled'
    && $st['ready_at'] === null && $st['request_code'] === $codeDelayed
    && array_key_exists('error', $st) && $st['error'] === null, $body);
[$code, $body] = api('GET', '/api/v1/calls/' . $call1, $B['key']);
$check('status for foreign client -> 404', $code === 404);
[$code, $body] = api('GET', '/api/v1/calls/' . $call1);
$check('status w/o key -> 401', $code === 401);

// ----------------- rule 2 + download flows -----------------
$rows = [['ping' => 1, 'pad' => 'x'], ['ping' => 2, 'pad' => 'y']];
$json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$fileName = bin2hex(random_bytes(8)) . '.json';
\Core\Filesystem::prepare();
file_put_contents(\Core\Filesystem::resultsDir() . '/' . $fileName, $json);
$extraFiles[] = \Core\Filesystem::resultsDir() . '/' . $fileName;
// A 48-hex string of exactly the shape the retired execution-level download
// token used to have. Nothing issues such a token any more, so it must not be
// a credential.
$unissued = \Core\Str::hex(24);
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, finished_at, ready_at, expires_at,
        result_path, result_size_bytes, row_count)
     VALUES (?, 'delayed', 'ready', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, ?, 2)",
    [$reqDelayed, $now, $now, $now, $now, 'results/' . $fileName, strlen($json)]
);
$readyExecId = $db->insertId();

$db->execute('UPDATE executions SET status = ? WHERE request_id = ? AND status IN (?, ?)',
    ['expired', $reqDelayed, 'scheduled', 'running']); // drain so rule 2 can win its turn
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, $A['key']);
$payload3 = json_decode($body, true);
$check('rule 2 -> link returned now', $code === 200 && ($payload3['status'] ?? '') === 'ready'
    && is_string($payload3['link'] ?? null) && str_contains((string)$payload3['link'], '/api/v1/results/')
    && ($payload3['expires_at'] ?? null) !== null, $body);

$dlToken = basename((string)parse_url((string)($payload3['link'] ?? ''), PHP_URL_PATH));
[$code, $body, $type] = api('GET', '/api/v1/results/' . $dlToken, $A['key']);
$check('download streams the file', $code === 200 && str_contains($type, 'application/json')
    && json_decode($body, true) === $rows
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'downloaded'") >= 1);
[$code, $body] = api('GET', '/api/v1/results/' . $dlToken, $B['key']);
$check('download by foreign client -> 404', $code === 404);
$check("the foreign attempt is audited as 'denied' on that call",
    (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'denied' AND call_id = ?",
        [(string)($payload3['call_id'] ?? '')]) === 1);
[$code, $body] = api('GET', '/api/v1/results/');
$check('bad token format -> 404', $code === 404);

// The per-call token is the only download credential: an unissued 48-hex
// string of the shape the retired execution token had resolves to nothing,
// even for the client that would have owned the execution.
[$code, $body] = api('GET', '/api/v1/results/' . $unissued, $A['key']);
$check('unissued execution-shaped token -> 404', $code === 404);
[$code, $body] = api('GET', '/api/v1/results/' . $unissued, $B['key']);
$check('unissued execution-shaped token for another client -> 404', $code === 404);

$db->execute('UPDATE executions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$readyExecId]);
[$code, $body] = api('GET', '/api/v1/results/' . $dlToken, $A['key']);
$check('expired -> 410 + marked', $code === 410
    && (int)$db->value("SELECT status = 'expired' FROM executions WHERE id = ?", [$readyExecId]) === 1);

// ----------------- instant flows -----------------
[$code, $body] = api('POST', '/api/v1/request/' . $codeInst, $A['key']);
$inst = json_decode($body, true);
$check('instant executes synchronously', $code === 200
    && ($inst['mode'] ?? '') === 'instant' && ($inst['status'] ?? '') === 'ready'
    && (int)($inst['row_count'] ?? 0) === 1 && ($inst['truncated'] ?? true) === false
    && ($inst['data'][0]['ping'] ?? '') === '1'
    && str_contains((string)($inst['executed_at'] ?? ''), '+03:30'), $body);
$check('instant execution row ready',
    (int)$db->value("SELECT COUNT(*) FROM executions WHERE request_id = ? AND mode = 'instant' AND status = 'ready' AND row_count = 1", [$reqInst]) === 1);
$check('instant response carries a download link', is_string($inst['link'] ?? null)
    && str_contains((string)$inst['link'], '/api/v1/results/'), (string)($inst['link'] ?? ''));

$instExec = $db->one("SELECT * FROM executions WHERE request_id = ? AND mode = 'instant' ORDER BY id DESC LIMIT 1", [$reqInst]);
$check('instant result file written with TTL', $instExec !== null
    && (string)$instExec['result_path'] === 'results/' . (int)$instExec['id'] . '.json'
    && $instExec['expires_at'] !== null
    && is_file(\Core\Filesystem::absoluteFromRelative((string)$instExec['result_path'])));
$fileData = json_decode((string)file_get_contents(\Core\Filesystem::absoluteFromRelative((string)$instExec['result_path'])), true);
$check('instant file envelope matches delayed shape', ($fileData['meta']['mode'] ?? '') === 'instant'
    && (int)($fileData['meta']['execution_id'] ?? 0) === (int)$instExec['id']
    && ($fileData['data'][0]['ping'] ?? null) === '1');

$linkToken = basename((string)parse_url((string)($inst['link'] ?? ''), PHP_URL_PATH));
[$code, $body] = api('GET', '/api/v1/results/' . $linkToken, $A['key']);
$dled = json_decode($body, true);
$check('instant link downloads the file', $code === 200
    && (int)($dled['meta']['execution_id'] ?? 0) === (int)$instExec['id']
    && ($dled['data'][0]['ping'] ?? null) === '1');

[$code, $body] = api('GET', '/api/v1/calls/' . (string)$inst['call_id'], $A['key']);
$stInst = json_decode($body, true);
$check('status for instant call carries link', $code === 200 && ($stInst['status'] ?? '') === 'ready'
    && is_string($stInst['link'] ?? null)
    && array_key_exists('error', $stInst) && $stInst['error'] === null);

[$code, $body, , $hdrs] = api('POST', '/api/v1/request/' . $codeInstDl . '?download=1', $A['key'], true);
$direct = json_decode($body, true);
$check('?download=1 returns the file directly', $code === 200
    && (bool)preg_match('/^content-disposition:\s*attachment/im', $hdrs)
    && ($direct['data'][0]['ping'] ?? null) === '1'
    && (($direct['meta']['mode'] ?? '') === 'instant'), $body);
[$code, $body] = api('POST', '/api/v1/request/' . $codeInst, $A['key']);
$inst2 = json_decode($body, true);
$check('instant cache hit replays same data', $code === 200 && ($inst2['data'] ?? null) === $inst['data']
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'instant_cache_hit'") >= 1);

// P1-2: inline instant size cap -> 413 (run_all sets GATEWAY_INSTANT_MAX_BYTES=2048).
[$code, $body] = api('POST', '/api/v1/request/' . $codeBig, $A['key']);
$check('instant result too large -> 413', $code === 413 && $getErr($body) === 'result_too_large', $body);
$check('too-large execution marked failed',
    (int)$db->value("SELECT COUNT(*) FROM executions WHERE request_id = ? AND mode = 'instant' AND status = 'failed'", [$reqBig]) === 1);

// P1-3: cached instant run whose file vanished -> 502, never an empty 200.
[$code, $body] = api('POST', '/api/v1/request/' . $codeCacheMiss, $A['key']);
$check('cache-miss fixture run succeeds', $code === 200 && ($body !== ''));
$missExec = $db->one("SELECT * FROM executions WHERE request_id = ? AND mode = 'instant' AND status = 'ready' ORDER BY id DESC LIMIT 1", [$reqCacheMiss]);
if ($missExec !== null && $missExec['result_path'] !== null) {
    \Worker\Cleanup::safeUnlink((string)$missExec['result_path']);
}
[$code, $body] = api('POST', '/api/v1/request/' . $codeCacheMiss, $A['key']);
$check('cache replay without file -> 502', $code === 502 && $getErr($body) === 'result_unavailable', $body);

// P2-9: the stored 24 h window on $codeClamp is clamped to the result file
// lifetime, so a run the sweep is about to expire is never replayed from it,
// while a live run still is.
[$code, $body] = api('POST', '/api/v1/request/' . $codeClamp, $A['key']);
$clampExec = $db->one("SELECT * FROM executions WHERE request_id = ? AND mode = 'instant' AND status = 'ready' ORDER BY id DESC LIMIT 1", [$reqClamp]);
$check('clamped fixture run wrote a result with a lifetime', $code === 200 && $clampExec !== null
    && $clampExec['expires_at'] !== null, $body);
if ($clampExec !== null) {
    // Age it past the file lifetime but keep it well inside the stored 24 h
    // window, which is exactly how an un-swept expired run looks.
    $ttlMinutes = (int)\Core\Config::get('limits.instant.result_ttl_minutes', 60);
    $db->execute(
        'UPDATE executions SET finished_at = DATE_SUB(NOW(), INTERVAL ? MINUTE),
                ready_at = DATE_SUB(NOW(), INTERVAL ? MINUTE),
                expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
         WHERE id = ?',
        [$ttlMinutes + 5, $ttlMinutes + 5, (int)$clampExec['id']]
    );
    [$code, $body] = api('POST', '/api/v1/request/' . $codeClamp, $A['key']);
    $clampSecond = json_decode((string)$body, true);
    $clampCall2 = $db->one('SELECT execution_id, was_shared FROM calls WHERE id = ?', [(string)($clampSecond['call_id'] ?? '')]);
    $check('run past the file lifetime is not replayed', $code === 200 && $clampCall2 !== null
        && (int)$clampCall2['was_shared'] === 0
        && (int)$clampCall2['execution_id'] !== (int)$clampExec['id'], $body);
    $clampNew = $clampCall2['execution_id'] ?? null;

    [$code, $body] = api('POST', '/api/v1/request/' . $codeClamp, $A['key']);
    $clampThird = json_decode((string)$body, true);
    $clampCall3 = $db->one('SELECT execution_id, was_shared FROM calls WHERE id = ?', [(string)($clampThird['call_id'] ?? '')]);
    $check('clamped window still shares a live run', $code === 200 && $clampCall3 !== null
        && (int)$clampCall3['was_shared'] === 1
        && (int)$clampCall3['execution_id'] === (int)$clampNew, $body);
}

[$code, $body] = api('POST', '/api/v1/request/' . $codeBad, $A['key']);
$check('query failure -> 502 generic', $code === 502 && $getErr($body) === 'execution_failed'
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'failed' AND request_id = ?", [$reqBad]) >= 1
    && str_contains((string)$db->value('SELECT error_message FROM executions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$reqBad]), '_table_that_does_not_exist'));
$badCall = (string)$db->value(
    "SELECT c.id FROM calls c JOIN executions e ON e.id = c.execution_id
     WHERE e.request_id = ? AND e.status = 'failed' ORDER BY e.id DESC LIMIT 1",
    [$reqBad]
);
[$code, $body] = api('GET', '/api/v1/calls/' . $badCall, $A['key']);
$stBad = json_decode($body, true);
$check('failed call status carries the sanitized error', $code === 200
    && ($stBad['status'] ?? '') === 'failed'
    && ($stBad['error']['code'] ?? '') === 'execution_failed'
    && strlen((string)($stBad['error']['message'] ?? '')) > 10
    && !str_contains((string)$body, '_table_that_does_not_exist'), substr((string)$body, 0, 220));
[$code, $body] = api('POST', '/api/v1/request/' . $codeSlow, $A['key']);
$check('query timeout -> 504', $code === 504 && $getErr($body) === 'timeout'
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'timeout' AND request_id = ?", [$reqSlow]) >= 1);

[$code, $body] = api('POST', '/api/v1/request/' . $codeRl, $A['key']); // warm-up (passes, cap free)
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$A['id'], $reqRl, $now]);
// One slot taken by a DELAYED run and one by an instant run: the connection is
// at its cap of 2 for the instant path too (shared counter, P2-10).
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, row_count, truncated)
     VALUES (?, 'delayed', 'running', ?, ?, 0, 0), (?, 'instant', 'running', ?, ?, 0, 0)",
    [$reqRl, $now, $now, $reqRl, $now, $now]
);
[$code, $body, , $rawHeaders] = api('POST', '/api/v1/request/' . $codeRl, $A['key'], true);
$hasRetryAfter = (bool)preg_match('/^retry-after:\s*\d+/mi', $rawHeaders);
$check('shared connection cap -> 429 + Retry-After', $code === 429 && $hasRetryAfter && $getErr($body) === 'rate_limited', $body);
// Release the two fake running rows: the cap is per CONNECTION for both modes
// (P2-10), so leaving them would 429 every instant submit below.
$db->execute("UPDATE executions SET status = 'failed', finished_at = NOW()
              WHERE status = 'running' AND request_id = ?", [$reqRl]);

// ------------------------------ Idempotency --------------------------------
// A retried submit that carries the Idempotency-Key it already used answers
// from the call that key created. Both fixtures have caching OFF, so a replay
// can only come from the header, never from the normal attach rules.
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], false, ['Idempotency-Key: short']);
$check('too-short Idempotency-Key -> 400 invalid_idempotency_key',
    $code === 400 && $getErr($body) === 'invalid_idempotency_key', $body);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], false,
    ['Idempotency-Key: ' . str_repeat('k', 201)]);
$check('too-long Idempotency-Key -> 400 invalid_idempotency_key',
    $code === 400 && $getErr($body) === 'invalid_idempotency_key');
$check('a rejected key leaves no submission behind',
    (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemInst]) === 0);

$iKey = 'idem-' . $run . '-instant';
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$idem1 = json_decode($body, true);
$check('idempotent: the first instant submit runs the query', $code === 200
    && ($idem1['status'] ?? '') === 'ready' && (int)($idem1['row_count'] ?? 0) === 1, substr((string)$body, 0, 200));
$iCall = (string)($idem1['call_id'] ?? '');
$iExec = (int)$db->value('SELECT execution_id FROM calls WHERE id = ?', [$iCall]);
$storedHash = (string)$db->value('SELECT idempotency_key_hash FROM calls WHERE id = ?', [$iCall]);
$check('the header value is stored only as a SHA-256', strlen($storedHash) === 64
    && $storedHash === hash('sha256', $iKey) && !str_contains($storedHash, $iKey));

$execsAfterFirst = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$idem2 = json_decode($body, true);
$check('idempotent: the retry replays the same call and link', $code === 200
    && ($idem2['call_id'] ?? '') === $iCall && ($idem2['link'] ?? null) === ($idem1['link'] ?? null)
    && ($idem2['data'] ?? null) == ($idem1['data'] ?? null), substr((string)$body, 0, 200));
$check('idempotent: the retry ran nothing',
    (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]) === $execsAfterFirst
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemInst]) === 1);
$check("the replay is audited as 'idempotent_replay'", (int)$db->value(
    "SELECT COUNT(*) FROM request_logs WHERE event = 'idempotent_replay' AND call_id = ?", [$iCall]) === 1);

[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key']);
$idem3 = json_decode($body, true);
$check('the same submit without the key runs again', ($idem3['call_id'] ?? '') !== $iCall
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]) === $execsAfterFirst + 1,
    substr((string)$body, 0, 200));

$dKey = 'idem-' . $run . '-download';
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst . '?download=1', $A['key'], false,
    ['Idempotency-Key: ' . $dKey]);
$dlFirst = $body;
$dlCall = (string)$db->value(
    'SELECT id FROM calls WHERE request_id = ? AND idempotency_key_hash = ? ORDER BY created_at DESC LIMIT 1',
    [$reqIdemInst, hash('sha256', $dKey)]
);
$check('idempotent: ?download=1 first submit delivers the file', $code === 200
    && str_contains((string)$dlFirst, '"data"') && $dlCall !== '', substr((string)$dlFirst, 0, 120));
$execsBeforeDlRetry = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst . '?download=1', $A['key'], false,
    ['Idempotency-Key: ' . $dKey]);
$check('idempotent: the ?download=1 retry replays that file byte for byte', $code === 200 && $body === $dlFirst
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]) === $execsBeforeDlRetry
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE idempotency_key_hash = ?', [hash('sha256', $dKey)]) === 1);

$fKey = 'idem-' . $run . '-failed';
[$code, $body] = api('POST', '/api/v1/request/' . $codeBad, $A['key'], false, ['Idempotency-Key: ' . $fKey]);
$check('idempotent: a failing instant run answers 502', $code === 502
    && $getErr($body) === 'execution_failed', $body);
$badExecs = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqBad]);
$badCalls = (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqBad]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeBad, $A['key'], false, ['Idempotency-Key: ' . $fKey]);
$check('idempotent: the retry replays the failure instead of running the query again',
    $code === 502 && $getErr($body) === 'execution_failed'
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqBad]) === $badExecs
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqBad]) === $badCalls);

$execsBeforeExpire = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]);
$db->execute("UPDATE executions SET status = 'expired', result_path = NULL WHERE id = ?", [$iExec]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$check('idempotent: an expired instant replay says gone instead of re-running', $code === 410
    && $getErr($body) === 'expired'
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]) === $execsBeforeExpire,
    $body);

[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $B['key'], false, ['Idempotency-Key: ' . $iKey]);
$idemB = json_decode($body, true);
$check('the same key for another client is its own submission', $code === 200
    && ($idemB['call_id'] ?? '') !== $iCall
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE client_id = ? AND idempotency_key_hash = ?',
        [$B['id'], hash('sha256', $iKey)]) === 1, substr((string)$body, 0, 200));

// Same header value, different request: the key is scoped per request.
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemDelay, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$delay1 = json_decode($body, true);
$check('the same key on another request is its own submission', $code === 200
    && ($delay1['call_id'] ?? '') !== $iCall && ($delay1['status'] ?? '') === 'scheduled', substr((string)$body, 0, 200));
$dCall = (string)($delay1['call_id'] ?? '');
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemDelay, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$delay2 = json_decode($body, true);
$check('idempotent: a keyed retry of a scheduled delayed run replays it', $code === 200
    && ($delay2['call_id'] ?? '') === $dCall && ($delay2['status'] ?? '') === 'scheduled'
    && array_key_exists('link', $delay2) && $delay2['link'] === null
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemDelay]) === 1
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemDelay]) === 1,
    substr((string)$body, 0, 200));
$dExec = (int)$db->value('SELECT execution_id FROM calls WHERE id = ?', [$dCall]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemDelay, $A['key']);
$delay3 = json_decode($body, true);
$check('a keyless delayed submit is its own call on the same execution',
    ($delay3['call_id'] ?? '') !== $dCall && ($delay3['status'] ?? '') === 'scheduled'
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemDelay]) === 1,
    substr((string)$body, 0, 200));

$delayFile = bin2hex(random_bytes(8)) . '.json';
file_put_contents(\Core\Filesystem::resultsDir() . '/' . $delayFile, '[{"ping":7}]');
$extraFiles[] = \Core\Filesystem::resultsDir() . '/' . $delayFile;
$db->execute(
    "UPDATE executions SET status = 'ready', finished_at = NOW(), ready_at = NOW(),
        expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE), result_path = ?, row_count = 1
     WHERE id = ?",
    ['results/' . $delayFile, $dExec]
);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemDelay, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$delay4 = json_decode($body, true);
$check('idempotent: a keyed retry of a ready delayed run returns that call and its link', $code === 200
    && ($delay4['call_id'] ?? '') === $dCall && ($delay4['status'] ?? '') === 'ready'
    && is_string($delay4['link'] ?? null) && array_key_exists('error', $delay4) && $delay4['error'] === null,
    substr((string)$body, 0, 200));
$dlTokenIdem = basename((string)parse_url((string)($delay4['link'] ?? ''), PHP_URL_PATH));
[$code, $body] = api('GET', '/api/v1/results/' . $dlTokenIdem, $A['key']);
$check('the replayed link downloads the stored result', $code === 200
    && json_decode($body, true) === [['ping' => 7]], $body);

$db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$dExec]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemDelay, $A['key'], false, ['Idempotency-Key: ' . $iKey]);
$delay5 = json_decode($body, true);
$check('idempotent: a keyed retry after expiry says expired instead of scheduling a new run', $code === 200
    && ($delay5['call_id'] ?? '') === $dCall && ($delay5['status'] ?? '') === 'expired'
    && array_key_exists('link', $delay5) && $delay5['link'] === null
    && (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemDelay]) === 1,
    substr((string)$body, 0, 200));

echo $pass === $total ? '=== API SMOKE PASS (' . $total . '/' . $total . ') ===' : "=== API SMOKE: {$pass}/{$total} ===", PHP_EOL;
exit($pass === $total ? 0 : 1);
