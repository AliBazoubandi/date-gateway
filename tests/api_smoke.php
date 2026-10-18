<?php
// End-to-end smoke test for the JSON API (build step 3).
// Bootstraps its own fixtures (connection, requests, clients, permissions),
// then drives /api/v1/* over php -S with curl, asserting:
//   auth failures (401/403/404 + 'denied' logs), delayed cache rule 1 (share),
//   status polling (owner-only), rule 2 attach with fresh call token,
//   chunked download, 410 expiry, instant run + result file + link +
//   ?download=1 file delivery / cache hit / 504 / 502 / 429.
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
array_push($requestIds, $reqDelayed, $reqDeny, $reqInst, $reqBad, $reqSlow, $reqRl, $reqInstDl);

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

function api(string $method, string $path, ?string $key = null, bool $tailHeaders = false): array
{
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => $tailHeaders];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = '';
    }
    if ($key !== null) {
        $opts[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $key];
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
    && $st['ready_at'] === null && $st['request_code'] === $codeDelayed, $body);
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
$token = \Core\Str::hex(24);
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, finished_at, ready_at, expires_at,
        result_path, result_size_bytes, row_count, download_token_hash)
     VALUES (?, 'delayed', 'ready', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, ?, 2, ?)",
    [$reqDelayed, $now, $now, $now, $now, 'results/' . $fileName, strlen($json), hash('sha256', $token)]
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
[$code, $body] = api('GET', '/api/v1/results/');
$check('bad token format -> 404', $code === 404);

[$code, $body] = api('GET', '/api/v1/results/' . $token, $A['key']);
$check('execution token download -> 200', $code === 200 && json_decode($body, true) === $rows);
[$code, $body] = api('GET', '/api/v1/results/' . $token, $B['key']);
$check('execution token for foreign client -> 404', $code === 404);

$db->execute('UPDATE executions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$readyExecId]);
[$code, $body] = api('GET', '/api/v1/results/' . $token, $A['key']);
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
    && is_string($stInst['link'] ?? null));

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

[$code, $body] = api('POST', '/api/v1/request/' . $codeBad, $A['key']);
$check('query failure -> 502 generic', $code === 502 && $getErr($body) === 'execution_failed'
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'failed' AND request_id = ?", [$reqBad]) >= 1
    && str_contains((string)$db->value('SELECT error_message FROM executions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$reqBad]), '_table_that_does_not_exist'));
[$code, $body] = api('POST', '/api/v1/request/' . $codeSlow, $A['key']);
$check('query timeout -> 504', $code === 504 && $getErr($body) === 'timeout'
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'timeout' AND request_id = ?", [$reqSlow]) >= 1);

[$code, $body] = api('POST', '/api/v1/request/' . $codeRl, $A['key']); // warm-up (passes, cap free)
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$A['id'], $reqRl, $now]);
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, row_count, truncated)
     VALUES (?, 'instant', 'running', ?, ?, 0, 0), (?, 'instant', 'running', ?, ?, 0, 0)",
    [$reqRl, $now, $now, $reqRl, $now, $now]
);
[$code, $body, , $rawHeaders] = api('POST', '/api/v1/request/' . $codeRl, $A['key'], true);
$hasRetryAfter = (bool)preg_match('/^retry-after:\s*\d+/mi', $rawHeaders);
$check('instant cap -> 429 + Retry-After', $code === 429 && $hasRetryAfter && $getErr($body) === 'rate_limited', $body);

echo $pass === $total ? '=== API SMOKE PASS (' . $total . '/' . $total . ') ===' : "=== API SMOKE: {$pass}/{$total} ===", PHP_EOL;
exit($pass === $total ? 0 : 1);
