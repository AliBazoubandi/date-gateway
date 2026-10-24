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
// run_all.php points the gateway at a throwaway DB + storage dir (see
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
$codeIdemFlight = "smk{$run}.idem_flight";

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
// Slow enough that two submits with the same key really overlap.
$reqIdemFlight = $addRequest($codeIdemFlight, 'instant', 30, 100, 0, 'SELECT SLEEP(2) AS slow, 1 AS ping');
array_push($requestIds, $reqDelayed, $reqDeny, $reqInst, $reqBad, $reqSlow, $reqRl, $reqInstDl, $reqBig, $reqCacheMiss, $reqClamp,
    $reqIdemInst, $reqIdemDelay, $reqIdemFlight);

// mkClient writes api_key_hash directly (bare SHA-256, the pre-pepper form).
// Pass peppered=true to also store api_key_hash_hmac, as the admin panel and
// the rotation path do — that row authenticates through the peppered branch,
// while a plain row proves the legacy branch still works and upgrades itself
// on first use.
$mkClient = static function (string $name, bool $active = true, bool $peppered = false) use ($db, $now): array {
    $key = 'gw_smoketest_' . \Core\Str::hex(16);
    $db->execute(
        'INSERT INTO clients (name, type, api_key_hash, api_key_hash_hmac, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$name, 'agent', hash('sha256', $key),
            $peppered ? hash_hmac('sha256', $key, (string)\Core\Config::get('security.master_key', '')) : null,
            $active ? 1 : 0, $now]
    );
    $id = $db->insertId();
    return ['id' => $id, 'key' => $key];
};
$A = $mkClient("smk {$run} A");
$B = $mkClient("smk {$run} B", true, true);
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
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)',
    [$A['id'], $reqIdemFlight, $now]);

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

function api(string $method, string $path, ?string $key = null, bool $tailHeaders = false, array $extraHeaders = [], ?string $postBody = null): array
{
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    // Raw response headers, kept even for a refused transfer: libcurl throws
    // its buffered header text away when a transfer is cut short (the expired
    // download refusal sends headers and no body — err 18), so with
    // $tailHeaders the header block is collected through the callback instead.
    $headerText = '';
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($tailHeaders) {
        $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$headerText): int {
            $headerText .= $line;
            return strlen($line);
        };
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $postBody ?? '';
    }
    $httpHeaders = $extraHeaders;
    if ($postBody !== null && !preg_grep('/^Content-Type:/i', $httpHeaders)) {
        $httpHeaders[] = 'Content-Type: application/json';
    }
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
    $errno = curl_errno($ch); // e.g. CURLE_PARTIAL_FILE on a refused download transfer
    $headers = $tailHeaders ? $headerText : '';
    curl_close($ch);
    return [$code, $raw, $type, $headers, $errno];
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
// The Ymd_His part of the deterministic archive name, built from a row's
// expires_at exactly like Services\ResultArchive does.
$stampOf = static function (array $row): string {
    return (new DateTimeImmutable((string)$row['expires_at'], \Core\Time::timezone()))->format('Ymd_His');
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

// ----------------- catalog / discovery endpoint -----------------
[$code, $body] = api('GET', '/api/v1/requests');
$check('catalog no auth -> 401', $code === 401 && $getErr($body) === 'unauthorized');

[$code, $body] = api('GET', '/api/v1/requests', $C['key']);
$check('catalog inactive client -> 403', $code === 403 && $getErr($body) === 'forbidden');

[$code, $body] = api('POST', '/api/v1/requests', $A['key']);
$check('catalog POST -> 405', $code === 405 && $getErr($body) === 'method_not_allowed');

[$code, $body] = api('GET', '/api/v1/requests', $A['key']);
$catA = json_decode($body, true);
$check('catalog client A -> 200', $code === 200 && is_array($catA));
$check('catalog returns client info', ($catA['client']['name'] ?? '') === "smk {$run} A" && ($catA['client']['type'] ?? '') === 'agent');
$check('catalog returns endpoints directory',
    isset($catA['endpoints']['submit'], $catA['endpoints']['status'], $catA['endpoints']['download'], $catA['endpoints']['health']));
$codesA = array_column($catA['requests'] ?? [], 'code');
$check('catalog includes granted requests for client A', in_array($codeDelayed, $codesA, true) && in_array($codeInst, $codesA, true));
$check('catalog excludes ungranted requests for client A', !in_array($codeDeny, $codesA, true));

// Test alias /api/v1/catalog
[$code, $body] = api('GET', '/api/v1/catalog', $B['key']);
$catB = json_decode($body, true);
$check('catalog alias /api/v1/catalog -> 200', $code === 200 && is_array($catB));
$codesB = array_column($catB['requests'] ?? [], 'code');
$check('catalog client B only sees its granted request', $codesB === [$codeIdemInst]);

// ----------------- delayed flow + rule 1 -----------------
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, $A['key']);
$payload = json_decode($body, true);
$check('first submit -> 200 scheduled', $code === 200 && ($payload['status'] ?? '') === 'scheduled'
    && isset($payload['call_id']) && $payload['link'] === null && $payload['expires_at'] === null, $body);
$call1 = (string)$payload['call_id'];
// Client A was created with the legacy bare SHA-256 hash only: this request
// authenticated through the legacy branch and must have upgraded the row.
$check('legacy key upgraded to the peppered hash on first use',
    strlen((string)$db->value('SELECT api_key_hash_hmac FROM clients WHERE id = ?', [$A['id']])) === 64);
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
// B was created with the peppered hash already stored: this 404 (not 401)
// proves the peppered-hash lookup branch authenticated it.
$check('peppered-hash lookup authenticates', $code === 404);
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
[$code, $body, $type, $dlHeaders] = api('GET', '/api/v1/results/' . $dlToken, $A['key'], true);
$check('download streams the file', $code === 200 && str_contains($type, 'application/json')
    && json_decode($body, true) === $rows
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'downloaded'") >= 1);
// The delivered file is named exactly like the archived evidence copy of the
// same run: {code}_{expires stamp Ymd_His Tehran}_client{client}_e{id}.json.
$dlName = '';
if (preg_match('/^content-disposition:\s*attachment;\s*filename="([^"]*)"\s*$/im', $dlHeaders, $m)) {
    $dlName = $m[1];
}
$readyExp = $db->one('SELECT expires_at FROM executions WHERE id = ?', [$readyExecId]);
$expectedName = $codeDelayed . '_' . $stampOf($readyExp) . '_client' . $A['id'] . '_e' . $readyExecId . '.json';
$check('the download carries the run\'s archive name', $dlName === $expectedName,
    $dlName !== '' ? $dlName : '(no content-disposition) vs ' . $expectedName);
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
[$code, $body, $type, $hdrs, $err] = api('GET', '/api/v1/results/' . $dlToken, $A['key'], true);
$check('expired -> 410 status, marked, and no file is sent',
    $code === 410 && $err === CURLE_PARTIAL_FILE && $body === ''
    && str_contains($type, 'application/json')
    && !str_contains(strtolower($hdrs), 'content-disposition')
    && json_decode($body, true) !== $rows
    && (int)$db->value("SELECT status = 'expired' FROM executions WHERE id = ?", [$readyExecId]) === 1,
    "code={$code} err={$err} body=" . substr($body, 0, 80));
$check('the 410 says why in X-Gateway headers instead of a body',
    (bool)preg_match('/^x-gateway-error:\s*expired\r?$/mi', $hdrs)
    && (bool)preg_match('/^x-gateway-message:\s*This result has expired\.\r?$/mi', $hdrs),
    trim(str_replace("\r\n", ' | ', $hdrs)));
// The situation that forced the refusal design: a client re-runs its manual
// download command after the link expired. Any delivered body would be
// saved OVER the result file downloaded during the live window (curl -o
// writes error bodies too, and exits 0); with the refused delivery the REAL
// curl fails with a non-zero exit code and the previously saved file is
// byte-identical afterwards.
$keepPath = (string)(\Core\Filesystem::storageDir() . '/' . bin2hex(random_bytes(6)) . 'previous.json');
file_put_contents($keepPath, 'GOOD-PREVIOUS-RESULT');
$expiredUrl = 'http://127.0.0.1:' . $GLOBALS['port'] . '/api/v1/results/' . $dlToken; // the dev server itself, never a rebuilt link
$curlOut = [];
exec('curl.exe -sk -X POST -H "Authorization: Bearer ' . $A['key'] . '" -o "' . $keepPath . '" "' . $expiredUrl . '" 2>&1', $curlOut, $curlRc);
$check('curl -o after expiry fails instead of downloading anything',
    $curlRc !== 0
    && file_get_contents($keepPath) === 'GOOD-PREVIOUS-RESULT',
    'exit=' . $curlRc . ' content=' . substr((string)file_get_contents($keepPath), 0, 60));
unlink($keepPath);
// The refusal itself archives the file instead of unlinking it: the copy is
// named {code}_{expires stamp}_client{client}_e{execution id}.json — the stamp
// comes from the (artificially moved) expires_at, so it reflects the row's
// final state, not the name the live-window download announced.
$archivedDownloads = glob(\Core\Filesystem::archiveDir() . "/*_client{$A['id']}_e{$readyExecId}.json") ?: [];
$archivedExp = $db->one('SELECT expires_at FROM executions WHERE id = ?', [$readyExecId]);
$check('expired download leaves the result in the archive as evidence',
    $archivedDownloads !== [] && is_file($archivedDownloads[0])
    && str_starts_with((string)basename($archivedDownloads[0]), $codeDelayed . '_'),
    implode(' | ', array_map('basename', $archivedDownloads)));
$check('the archived copy carries the deterministic name of the run',
    $archivedDownloads !== [] && $archivedExp !== null
    && basename((string)$archivedDownloads[0]) === $codeDelayed . '_' . $stampOf($archivedExp)
        . '_client' . $A['id'] . '_e' . $readyExecId . '.json',
    implode(' | ', array_map('basename', $archivedDownloads)));
$extraFiles = array_merge($extraFiles, $archivedDownloads);

// ----------------- scheduler hold window -----------------
// A delayed execution the scheduler finished EARLY: the row is ready, but
// ready_at (the delay promise) is still in the future. Every client-facing
// path must hold the result until the window opens.
$holdFile = bin2hex(random_bytes(8)) . '.json';
file_put_contents(\Core\Filesystem::resultsDir() . '/' . $holdFile, $json);
$extraFiles[] = \Core\Filesystem::resultsDir() . '/' . $holdFile;
$holdReady = \Core\Time::mysql(\Core\Time::addMinutes(new DateTimeImmutable('now', \Core\Time::timezone()), 3));
$holdExpires = \Core\Time::mysql(\Core\Time::addMinutes(new DateTimeImmutable('now', \Core\Time::timezone()), 18));
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, finished_at, ready_at, expires_at,
        result_path, result_size_bytes, row_count)
     VALUES (?, 'delayed', 'ready', ?, ?, ?, ?, ?, ?, ?, 2)",
    [$reqDelayed, $holdReady, $now, $now, $holdReady, $holdExpires, 'results/' . $holdFile, strlen($json)]
);
$holdExecId = $db->insertId();
$holdToken = \Core\Str::hex(24);
$holdCallId = \Core\Str::hex(16);
$db->execute(
    'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, created_at, ip, was_shared)
     VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
    [$holdCallId, $A['id'], $reqDelayed, $holdExecId, hash('sha256', $holdToken), $now, 'api-test']
);

[$code, $body] = api('GET', '/api/v1/calls/' . $holdCallId, $A['key']);
$holdStatus = json_decode($body, true);
$check('hold: status reports scheduled until the promise opens',
    $code === 200 && ($holdStatus['status'] ?? '') === 'scheduled'
    && ($holdStatus['link'] ?? null) === null
    && ($holdStatus['ready_at'] ?? '') !== '', $body);

// Rule 2 attaches to a held result (no duplicate run) but answers 'scheduled'.
[$code, $body] = api('POST', '/api/v1/request/' . $codeDelayed, $A['key']);
$attach = json_decode($body, true);
$check('hold: a submit during the hold attaches without re-running and answers scheduled',
    $code === 200 && ($attach['status'] ?? '') === 'scheduled' && ($attach['link'] ?? null) === null
    && (int)$db->value("SELECT COUNT(*) FROM executions WHERE request_id = ? AND status = 'ready'", [$reqDelayed]) === 1,
    $body);

[$code, $body, $type, $hdrs] = api('GET', '/api/v1/results/' . $holdToken, $A['key'], true);
$retryAfter = 0;
if (preg_match('/^Retry-After:\s*(\d+)\r?$/mi', $hdrs, $m)) {
    $retryAfter = (int)$m[1];
}
$check('hold: download refused with 409 + Retry-After, no file body',
    $code === 409 && str_contains($type, 'application/json')
    && $getErr($body) === 'result_not_ready'
    && !str_contains(strtolower($hdrs), 'content-disposition')
    && json_decode($body, true) !== $rows // an error envelope, not the result bytes
    && $retryAfter > 0 && $retryAfter <= 180,
    "code={$code} retry={$retryAfter}");

// Once the window opens the same token streams the file.
$db->execute('UPDATE executions SET ready_at = ? WHERE id = ?', [$now, $holdExecId]);
[$code, $body, , $holdHdrs] = api('GET', '/api/v1/results/' . $holdToken, $A['key'], true);
$check('hold: download streams after the window opens',
    $code === 200 && json_decode($body, true) === $rows);
// The name a client downloaded is the name the archive eventually uses for
// the same run. This fixture is refused by STATUS alone (below), letting
// expires_at stand untouched, so nothing truncates it between the two events
// and the downloaded name and the archived name must match exactly.
$holdName = '';
if (preg_match('/^content-disposition:\s*attachment;\s*filename="([^"]*)"\s*$/im', $holdHdrs, $mh)) {
    $holdName = $mh[1];
}
$holdExp = $db->one('SELECT expires_at FROM executions WHERE id = ?', [$holdExecId]);
$check('hold: the download carries the run\'s archive name',
    $holdExp !== null
    && $holdName === $codeDelayed . '_' . $stampOf($holdExp) . '_client' . $A['id'] . '_e' . $holdExecId . '.json',
    $holdName . ' vs ' . ($holdExp['expires_at'] ?? '?'));

// Refusing by status alone (an already-expired row whose TTL stamp says
// nothing moved it): the answer is the same 410-with-no-body refusal, and the
// archived twin keeps EXACTLY the name the client downloaded a moment ago.
$db->execute("UPDATE executions SET status = 'expired' WHERE id = ?", [$holdExecId]);
[$code, $body, , , $holdErr] = api('GET', '/api/v1/results/' . $holdToken, $A['key'], true);
$check('hold: refusal by status is 410 with no delivered body',
    $code === 410 && $holdErr === CURLE_PARTIAL_FILE && $body === '');
$archivedHold = glob(\Core\Filesystem::archiveDir() . "/*_e{$holdExecId}.json") ?: [];
$check('the archived twin of a refused download keeps the downloaded name',
    $archivedHold !== [] && is_file($archivedHold[0]) && basename((string)$archivedHold[0]) === $holdName,
    implode(' | ', array_map('basename', $archivedHold)));
$extraFiles = array_merge($extraFiles, $archivedHold);

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
$directName = '';
if (preg_match('/^content-disposition:\s*attachment;\s*filename="([^"]*)"\s*$/im', $hdrs, $md)) {
    $directName = $md[1];
}
$directExecId = (int)($direct['meta']['execution_id'] ?? 0);
$directExp = $db->one('SELECT expires_at FROM executions WHERE id = ?', [$directExecId]);
$check('?download=1 carries the run\'s archive name',
    $directExp !== null
    && $directName === $codeInstDl . '_' . $stampOf($directExp) . '_client' . $A['id'] . '_e' . $directExecId . '.json',
    $directName . ' vs e' . $directExecId);
[$code, $body] = api('POST', '/api/v1/request/' . $codeInst, $A['key']);
$inst2 = json_decode($body, true);
$check('instant cache hit replays same data', $code === 200 && ($inst2['data'] ?? null) === $inst['data']
    && (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'instant_cache_hit'") >= 1);
$check('instant cache hit keeps the run\'s executed_at (not null)',
    is_string($inst2['executed_at'] ?? null) && $inst2['executed_at'] === $inst['executed_at'],
    json_encode([$inst['executed_at'] ?? null, $inst2['executed_at'] ?? null]));

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
    $check('a replayed cache hit reports when the run happened, not null',
        (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+03:30$/',
            (string)($clampThird['executed_at'] ?? '')), var_export($clampThird['executed_at'] ?? null, true));
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

// ------------------- a key whose instant run is still in flight -------------------
// BUG 1: the retry used to fall through and start a SECOND execution + call for
// one logical submission whenever the first run outlived the 10 s lock wait.
// The state that reproduces it is exactly this: a prior call whose execution is
// still `running`. The retry must answer 409 and write nothing.
$flightKey = 'idem-' . $run . '-flight';
$flightHash = hash('sha256', $flightKey);
$flightCall = \Core\Str::hex(16);
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, row_count, truncated)
     VALUES (?, 'instant', 'running', ?, ?, 0, 0)",
    [$reqIdemInst, $now, $now]
);
$flightExec = $db->insertId();
$db->execute(
    'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, idempotency_key_hash, created_at, ip, was_shared)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
    [$flightCall, $A['id'], $reqIdemInst, $flightExec, hash('sha256', \Core\Str::hex(24)),
        Encryption::encrypt(\Core\Str::hex(24)), $flightHash, $now, '127.0.0.1']
);
$flightExecs = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]);
$flightCalls = (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemInst]);
[$code, $body, , $flightHeaders] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], true,
    ['Idempotency-Key: ' . $flightKey]);
$check('in-flight instant submit -> 409 submission_in_progress', $code === 409
    && $getErr($body) === 'submission_in_progress'
    && str_contains($body, 'still running'), $body);
$check('the 409 carries Retry-After: 5', (bool)preg_match('/^retry-after:\s*5\s*$/mi', $flightHeaders),
    trim(str_replace("\r\n", ' | ', $flightHeaders)));
$check('the in-flight retry starts no execution and no call',
    (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]) === $flightExecs
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemInst]) === $flightCalls);
$check('the in-flight retry is not logged as a replay',
    (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE event = 'idempotent_replay' AND call_id = ?",
        [$flightCall]) === 0);

// Once that same run is ready, the retry replays it: same call, same link, and
// still no second execution.
$flightFile = bin2hex(random_bytes(8)) . '.json';
$flightJson = json_encode(['data' => [['ping' => '9']], 'meta' => ['mode' => 'instant']],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents(\Core\Filesystem::resultsDir() . '/' . $flightFile, $flightJson);
$extraFiles[] = \Core\Filesystem::resultsDir() . '/' . $flightFile;
$db->execute(
    "UPDATE executions SET status = 'ready', finished_at = NOW(), ready_at = NOW(),
        expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE), result_path = ?, row_count = 1,
        truncated = 1
     WHERE id = ?",
    ['results/' . $flightFile, $flightExec]
);
[$code, $body] = api('POST', '/api/v1/request/' . $codeIdemInst, $A['key'], false,
    ['Idempotency-Key: ' . $flightKey]);
$flightReplay = json_decode($body, true);
$flightLink = \Api\Links::forCall($flightCall);
$check('the ready retry replays the same call and link', $code === 200
    && ($flightReplay['call_id'] ?? '') === $flightCall
    && ($flightReplay['link'] ?? null) === $flightLink
    && ($flightReplay['data'][0]['ping'] ?? '') === '9', substr((string)$body, 0, 220));
// The drivers return TINYINT as a string; a replayed `truncated` must still be
// a real boolean true, not the string "1" nor false because '1' !== 1.
$check('a replayed run keeps its truncated flag as a boolean', ($flightReplay['truncated'] ?? null) === true,
    var_export($flightReplay['truncated'] ?? null, true));
$check('the ready replay still ran nothing',
    (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemInst]) === $flightExecs
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemInst]) === $flightCalls);

// Two submits with one key fired together at a 2 s query. Whichever order the
// dev server gives them, the invariant is one execution and one call: the
// loser either sees the winner in flight (409) or replays it (200).
$raceKey = 'idem-' . $run . '-race';
$raceHeaders = ['Authorization: Bearer ' . $A['key'], 'Idempotency-Key: ' . $raceKey];
$multi = curl_multi_init();
$raceHandles = [];
for ($i = 0; $i < 2; $i++) {
    $ch = curl_init('http://127.0.0.1:' . $port . '/api/v1/request/' . $codeIdemFlight);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $raceHeaders,
    ]);
    curl_multi_add_handle($multi, $ch);
    $raceHandles[] = $ch;
}
do {
    $mrc = curl_multi_exec($multi, $raceActive);
    if ($raceActive > 0) {
        curl_multi_select($multi, 1.0);
    }
} while ($raceActive > 0 && $mrc === CURLM_OK);
$raceResults = [];
foreach ($raceHandles as $ch) {
    $raceResults[] = [
        'code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => (string)curl_multi_getcontent($ch),
    ];
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);
$raceExecs = (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqIdemFlight]);
$raceCalls = (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqIdemFlight]);
$raceCallIds = [];
$raceCodes = [];
$raceLoserOk = true;
foreach ($raceResults as $res) {
    $raceCodes[] = $res['code'];
    $decoded = json_decode($res['body'], true);
    if ($res['code'] === 200) {
        $raceCallIds[(string)($decoded['call_id'] ?? '')] = true;
        if (($decoded['status'] ?? '') !== 'ready' || ($decoded['data'][0]['ping'] ?? null) !== '1') {
            $raceLoserOk = false;
        }
    } elseif ($res['code'] === 409) {
        if (($decoded['error']['code'] ?? '') !== 'submission_in_progress') {
            $raceLoserOk = false;
        }
    } else {
        $raceLoserOk = false;
    }
}
sort($raceCodes);
$check('concurrent same-key submits: exactly one execution and one call',
    $raceExecs === 1 && $raceCalls === 1, "executions={$raceExecs} calls={$raceCalls} codes=" . implode(',', $raceCodes));
$check('concurrent same-key submits: the loser gets 409 or the winner\'s answer',
    $raceLoserOk && count($raceCallIds) === 1, json_encode($raceResults));

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

// --- Parameterized queries: catalog, validation, dynamic defaults, cache isolation ---
$codeParams = "smk{$run}.params";
$paramsJsonDef = json_encode([
    'start_date' => ['type' => 'date', 'required' => true, 'description' => 'Period start date'],
    'end_date' => ['type' => 'date', 'required' => false, 'default' => 'today', 'description' => 'Period end date'],
    'multiplier' => ['type' => 'int', 'required' => false, 'default' => 2, 'description' => 'Multiplier factor'],
]);
$db->execute(
    'INSERT INTO requests (code, name, sql_text, params_json, connection_id, response_mode, instant_cache_seconds, delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 15, ?, ?, 1, ?)',
    [$codeParams, $codeParams, "SELECT :start_date AS start_dt, :end_date AS end_dt, (:multiplier * 10) AS total",
        $paramsJsonDef, $connId, 'instant', 60, 10, 100, $now]
);
$reqIdParams = $db->insertId();
$requestIds[] = $reqIdParams;
$db->execute('INSERT INTO client_request_permissions (client_id, request_id) VALUES (?, ?)', [$A['id'], $reqIdParams]);

// Catalog discovery includes parameters
[$code, $body] = api('GET', '/api/v1/requests', $A['key']);
$catalog = json_decode($body, true);
$catReq = null;
foreach ($catalog['requests'] ?? [] as $r) {
    if (($r['code'] ?? '') === $codeParams) {
        $catReq = $r;
        break;
    }
}
$check('catalog lists parameterized request with its parameters schema', $code === 200
    && $catReq !== null
    && count($catReq['parameters'] ?? []) === 3
    && $catReq['parameters'][0]['name'] === 'start_date'
    && $catReq['parameters'][0]['required'] === true
    && $catReq['parameters'][1]['name'] === 'end_date'
    && $catReq['parameters'][1]['default'] === 'today'
    && $catReq['parameters'][2]['name'] === 'multiplier'
    && $catReq['parameters'][2]['type'] === 'int',
    substr((string)$body, 0, 300));

// Missing required parameter -> 400 missing_parameter
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams, $A['key']);
$errMissing = json_decode($body, true);
$check('submitting without required parameter returns 400 missing_parameter', $code === 400
    && ($errMissing['error']['code'] ?? '') === 'missing_parameter',
    $body);

// Invalid parameter type (bad date) -> 400 invalid_parameter
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams . '?start_date=2026-99-99', $A['key']);
$errInvalid = json_decode($body, true);
$check('submitting invalid date format returns 400 invalid_parameter', $code === 400
    && ($errInvalid['error']['code'] ?? '') === 'invalid_parameter',
    $body);

// Valid submit via URL query string + dynamic 'today' default
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams . '?start_date=2026-01-01', $A['key']);
$resUrl = json_decode($body, true);
$check('valid submit via query string returns rows with dynamic today default', $code === 200
    && ($resUrl['status'] ?? '') === 'ready'
    && ($resUrl['data'][0]['start_dt'] ?? '') === '2026-01-01'
    && ($resUrl['data'][0]['end_dt'] ?? '') === date('Y-m-d')
    && (int)($resUrl['data'][0]['total'] ?? 0) === 20,
    $body);

// Valid submit via JSON body with custom multiplier and end_date
$jsonPayload = json_encode([
    'params' => [
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'multiplier' => 5,
    ]
]);
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams, $A['key'], false, [], $jsonPayload);
$resJson = json_decode($body, true);
$check('valid submit via JSON body parses params, end_date and multiplier', $code === 200
    && ($resJson['status'] ?? '') === 'ready'
    && ($resJson['data'][0]['start_dt'] ?? '') === '2026-03-01'
    && ($resJson['data'][0]['end_dt'] ?? '') === '2026-03-31'
    && (int)($resJson['data'][0]['total'] ?? 0) === 50,
    $body);

// Cache isolation:
// 1. Submit with start_date=2026-05-01 -> fresh execution
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams . '?start_date=2026-05-01', $A['key']);
$c1 = json_decode($body, true);
$call1 = (string)($c1['call_id'] ?? '');
$exec1 = (int)$db->value('SELECT execution_id FROM calls WHERE id = ?', [$call1]);

// 2. Submit with start_date=2026-07-01 -> separate execution (different hash, no collision)
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams . '?start_date=2026-07-01', $A['key']);
$c2 = json_decode($body, true);
$call2 = (string)($c2['call_id'] ?? '');
$exec2 = (int)$db->value('SELECT execution_id FROM calls WHERE id = ?', [$call2]);
$check('different parameter values produce different executions (cache isolation)',
    $exec1 > 0 && $exec2 > 0 && $exec1 !== $exec2,
    "exec1={$exec1} exec2={$exec2}");

// 3. Submit again with start_date=2026-05-01 within cache window -> cache hit on exec1
[$code, $body] = api('POST', '/api/v1/request/' . $codeParams . '?start_date=2026-05-01', $A['key']);
$c3 = json_decode($body, true);
$call3 = (string)($c3['call_id'] ?? '');
$exec3 = (int)$db->value('SELECT execution_id FROM calls WHERE id = ?', [$call3]);
$wasShared3 = (int)$db->value('SELECT was_shared FROM calls WHERE id = ?', [$call3]);
$check('identical parameters reuse cached execution within cache window',
    $exec3 === $exec1 && $wasShared3 === 1,
    "exec3={$exec3} was_shared={$wasShared3}");

echo $pass === $total ? '=== API SMOKE PASS (' . $total . '/' . $total . ') ===' : "=== API SMOKE: {$pass}/{$total} ===", PHP_EOL;
exit($pass === $total ? 0 : 1);
