<?php
// End-to-end smoke test for webhook delivery (build step 5).
// Opens a REAL localhost HTTP receiver, runs the actual CLI entry point
// (php cli\webhooks.php via proc_open) and asserts: HMAC-SHA256 signature
// over the exact body bytes, payload fields (call_id, request_code, status,
// stable per-call link, +03:30 expiry, row_count), sent state, per-attempt
// logging, 500 -> backoff -> eventual success, exhaustion after max_attempts,
// and that clients without a webhook URL are skipped (stay pending).
// Usage: php tests\webhook_smoke.php

use Core\Filesystem;
use Core\Str;
use Core\Time;
use Services\Encryption;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label,
        $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$db = db();

// ------------------- self-cleaning fixtures, FK-safe order -------------------
$wipe = static function () use ($db): void {
    // Smoke-scoped only: a live gateway's real calls/logs/executions and
    // permission grants must never be wiped by a test run.
    $clients = "SELECT id FROM clients WHERE name LIKE 'webhook smk%'";
    $requests = "SELECT r.id FROM requests r JOIN connections c ON c.id = r.connection_id
                 WHERE r.code LIKE 'whk.%' OR c.name LIKE 'webhook smk%'";
    $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls
                  WHERE client_id IN ($clients) OR request_id IN ($requests))");
    $db->execute("DELETE FROM request_logs WHERE client_id IN ($clients) OR request_id IN ($requests)");
    $db->execute("DELETE FROM calls WHERE client_id IN ($clients) OR request_id IN ($requests)");
    $db->execute("DELETE FROM executions WHERE request_id IN ($requests)");
    $db->execute("DELETE FROM client_request_permissions WHERE client_id IN ($clients)");
    $db->execute("DELETE r FROM requests r JOIN connections c ON c.id = r.connection_id
                  WHERE r.code LIKE 'whk.%' OR c.name LIKE 'webhook smk%'");
    $db->execute("DELETE FROM connections WHERE name LIKE 'webhook smk%'");
    $db->execute("DELETE FROM clients WHERE name LIKE 'webhook smk%'");
};
$wipe();

$now = now_str();
$db->execute(
    'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted, is_active, created_at)
     VALUES (?, ?, ?, NULL, ?, ?, ?, 1, ?)',
    ['webhook smk conn', 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), $now]
);
$connId = $db->insertId();
$db->execute(
    'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
        delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
     VALUES (?, ?, ?, ?, ?, 0, 0, 15, 10, 100, 1, ?)',
    ['whk.ready', 'webhook smk request', 'SELECT 1 AS ping', $connId, 'delayed', $now]
);
$requestId = $db->insertId();

// Ready execution + a real result file, like the worker would leave behind.
$resultRel = 'results/whsmk_result.json';
$payloadRows = '{"data":[{"ping":"1"}],"meta":{"row_count":1}}';
file_put_contents(Filesystem::absoluteFromRelative($resultRel), $payloadRows);
$db->execute(
    "INSERT INTO executions (request_id, mode, status, scheduled_at, started_at, finished_at, ready_at,
        expires_at, result_path, result_size_bytes, row_count, download_token_hash)
     VALUES (?, 'delayed', 'ready', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, ?, 1, ?)",
    [$requestId, $now, $now, $now, $now, $resultRel, strlen($payloadRows), hash('sha256', Str::hex(24))]
);
$execId = $db->insertId();

$secret = Str::hex(24);

// ------------------- local HTTP receiver -------------------------------------
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot open receiver socket: {$errstr}" . PHP_EOL);
    exit(2);
}
$addr = (string)stream_socket_get_name($server, false);
$port = (int)substr((string)strrchr($addr, ':'), 1);
$check('receiver socket bound', $port > 0, (string)$port);

/**
 * Accept exactly one request (or timeout) and answer with $status.
 * Returns [method, path, headers(lower-keyed), body] or null on timeout.
 */
$receiveOne = static function (string $status) use ($server): ?array {
    $read = [$server];
    $w = null;
    $e = null;
    if (stream_select($read, $w, $e, 30) !== 1) {
        return null;
    }
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        return null;
    }
    stream_set_timeout($conn, 10);
    $buf = '';
    $bodyLen = null;
    while (true) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
        $cut = strpos($buf, "\r\n\r\n");
        if ($cut !== false) {
            if ($bodyLen === null) {
                $bodyLen = 0;
                foreach (explode("\r\n", substr($buf, 0, $cut)) as $line) {
                    $p = explode(':', strtolower($line), 2);
                    if (trim($p[0]) === 'content-length') {
                        $bodyLen = (int)trim($p[1]);
                    }
                }
            }
            if (strlen($buf) - $cut - 4 >= $bodyLen) {
                break;
            }
        }
    }
    fwrite($conn, "HTTP/1.1 {$status}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($conn);
    $cut = strpos($buf, "\r\n\r\n");
    if ($cut === false) {
        return null;
    }
    $head = substr($buf, 0, $cut);
    $body = substr($buf, $cut + 4, $bodyLen);
    $parts = explode(' ', $head);
    $headers = [];
    foreach (explode("\r\n", $head) as $h) {
        $p = explode(':', $h, 2);
        if (count($p) === 2) {
            $headers[strtolower(trim($p[0]))] = trim($p[1]);
        }
    }
    return [$parts[0] ?? '', $parts[1] ?? '', $headers, $body];
};

/**
 * Run one real CLI pass while the process is still alive: optionally serve
 * one webhook request ($status, null = expect no request), then reap it.
 * Returns [exitCode, combinedOutput, request|null].
 */
$exchange = static function (string $relScript, ?string $status) use ($receiveOne): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['file', BASE_PATH . '/storage/_whsmk_stderr.tmp', 'w']];
    $proc = proc_open('php ' . $relScript, $descriptors, $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        return [-1, 'spawn failed', null];
    }
    $req = $status !== null ? $receiveOne($status) : null;
    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $code = (int)proc_close($proc);
    $err = @file_get_contents(BASE_PATH . '/storage/_whsmk_stderr.tmp');
    @unlink(BASE_PATH . '/storage/_whsmk_stderr.tmp');
    return [$code, $out . ($err === false || $err === '' ? '' : ' ||ERR:: ' . trim($err)), $req];
};

// Fixture builders: a client (+url), a call with an encrypted token, a pending delivery.
$mkClient = static function (?string $url) use ($db, $now, $secret, $port): int {
    $db->execute(
        'INSERT INTO clients (name, type, api_key_hash, webhook_url, webhook_secret, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 1, ?)',
        ['webhook smk client ' . Str::hex(4), 'agent', hash('sha256', Str::hex(16)), $url, $secret, $now]
    );
    return $db->insertId();
};
$mkCallDelivery = static function (int $clientId) use ($db, $now, $execId, $requestId): array {
    $callId = Str::hex(16);
    $rawToken = Str::hex(24);
    $db->execute(
        'INSERT INTO calls (id, client_id, request_id, execution_id, token_hash, token_enc, created_at, ip, was_shared)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
        [$callId, $clientId, $requestId, $execId, hash('sha256', $rawToken),
            Encryption::encrypt($rawToken), $now, 'whsmk']
    );
    $db->execute(
        "INSERT INTO webhook_deliveries (call_id, attempt, state, created_at) VALUES (?, 0, 'pending', ?)",
        [$callId, $now]
    );
    return [$callId, $rawToken];
};
$redeliverNow = static function (string $callId) use ($db): void {
    $db->execute('UPDATE webhook_deliveries SET next_retry_at = NOW() WHERE call_id = ?', [$callId]);
};
$deliveryRow = static fn (string $callId): array
    => $db->one('SELECT * FROM webhook_deliveries WHERE call_id = ?', [$callId]) ?? [];

// ======================= 1) happy path: signed 200 ===========================
$clientA = $mkClient('http://127.0.0.1:' . $port . '/hook/gw');
[$callA, $tokenA] = $mkCallDelivery($clientA);
[$wCode, $wOut, $req] = $exchange('cli/webhooks.php', '200 OK');
$check('CLI webhooks exits 0', $wCode === 0 && !str_contains($wOut, 'ERR::'), substr($wOut, 0, 200));
$check('receiver got a POST', $req !== null && $req[0] === 'POST', $req === null ? 'timeout' : (string)$req[0]);
$reqPath = $req !== null ? $req[1] : '';
$reqHeaders = $req !== null ? $req[2] : [];
$reqBody = $req !== null ? $req[3] : '';
$check('receiver path matches webhook_url', $reqPath === '/hook/gw', $reqPath);
$check('content-type is application/json', ($reqHeaders['content-type'] ?? '') === 'application/json',
    (string)($reqHeaders['content-type'] ?? '-'));
$check('call id header matches', ($reqHeaders['x-gateway-call'] ?? '') === $callA,
    (string)($reqHeaders['x-gateway-call'] ?? '-'));

$sig = (string)($reqHeaders['x-gateway-signature'] ?? '');
$tsHeader = (string)($reqHeaders['x-gateway-timestamp'] ?? '');
$check('timestamp header fresh', $tsHeader !== '' && abs(time() - (int)$tsHeader) <= 300, $tsHeader);
$check('HMAC-SHA256 signature valid (timestamp-bound)', $sig !== '' && $tsHeader !== ''
    && hash_equals('sha256=' . hash_hmac('sha256', $tsHeader . '.' . $reqBody, $secret), $sig),
    $sig !== '' ? 'prefix ' . substr($sig, 0, 20) . '…' : 'missing');
$payload = json_decode($reqBody, true);
$check('payload fields', is_array($payload)
    && ($payload['call_id'] ?? '') === $callA
    && ($payload['request_code'] ?? '') === 'whk.ready'
    && ($payload['status'] ?? '') === 'ready'
    && (int)($payload['row_count'] ?? 0) === 1
    && str_contains((string)($payload['expires_at'] ?? ''), '+03:30'), substr($reqBody, 0, 200));
$check('payload carries the stable per-call link', ($payload['link'] ?? null) !== null
    && str_contains((string)$payload['link'], '/api/v1/results/' . $tokenA),
    (string)($payload['link'] ?? '-'));

$d = $deliveryRow($callA);
$check('delivery sent with status_code 200', ($d['state'] ?? '') === 'sent' && (int)($d['status_code'] ?? 0) === 200
    && $d['sent_at'] !== null && $d['next_retry_at'] === null, (string)($d['state'] ?? 'missing'));
$check('webhook_sent logged once', (int)$db->value(
    "SELECT COUNT(*) FROM request_logs WHERE event = 'webhook_sent' AND call_id = ?", [$callA]) === 1);

// =================== 2) failure -> backoff -> success ========================
[$callB] = $mkCallDelivery($clientA);
[, , $req] = $exchange('cli/webhooks.php', '500 Internal Server Error'); // send #1 fails
$d = $deliveryRow($callB);
$nextIn = ($d['next_retry_at'] ?? null) !== null
    ? Time::fromSql((string)$d['next_retry_at'])->getTimestamp() - time() : null;
$check('failed send stays pending with attempt=1', ($d['state'] ?? '') === 'pending' && (int)($d['attempt'] ?? 0) === 1
    && (int)($d['status_code'] ?? 0) === 500, 'state=' . (string)($d['state'] ?? '-'));
$check('retry scheduled ~1 min out (backoff step 1)', $nextIn !== null && $nextIn > 40 && $nextIn <= 65,
    $nextIn === null ? 'null' : (string)$nextIn . 's');
$check('webhook_failed logged for the attempt', (int)$db->value(
    "SELECT COUNT(*) FROM request_logs WHERE event = 'webhook_failed' AND call_id = ?", [$callB]) === 1);

$redeliverNow($callB);
[, , $req] = $exchange('cli/webhooks.php', '200 OK'); // send #2 succeeds
$d = $deliveryRow($callB);
$check('second send succeeds, state sent', ($d['state'] ?? '') === 'sent' && (int)($d['attempt'] ?? 0) === 1,
    'state=' . (string)($d['state'] ?? '-'));
$check('retry webhook also correctly signed', $req !== null
    && ($rTs = (string)($req[2]['x-gateway-timestamp'] ?? '')) !== ''
    && hash_equals('sha256=' . hash_hmac('sha256', $rTs . '.' . (string)$req[3], $secret),
        (string)($req[2]['x-gateway-signature'] ?? 'x')));

// =================== 3) exhaustion after max_attempts ========================
[$callC] = $mkCallDelivery($clientA);
$exhausted = false;
$d = [];
for ($i = 1; $i <= 6; $i++) { // 1 initial send + 5 retries (config webhook.max_attempts)
    $redeliverNow($callC);
    $exchange('cli/webhooks.php', '500 Internal Server Error');
    $d = $deliveryRow($callC);
    if (($d['state'] ?? '') === 'exhausted') {
        $exhausted = $i === 6; // only after ALL sends are burned
        break;
    }
    $check("send #{$i} retried, still pending", ($d['state'] ?? '') === 'pending' && (int)($d['attempt'] ?? 0) === $i);
}
$check('exhausted after 1 initial + 5 retries', $exhausted, 'state=' . (string)($d['state'] ?? '-'));
$check('exhausted row has no next_retry_at + last_error', ($d['next_retry_at'] ?? null) === null
    && str_contains((string)($d['last_error'] ?? ''), 'gave up'), (string)($d['last_error'] ?? '-'));
$check('every failed attempt logged (6 rows)', (int)$db->value(
    "SELECT COUNT(*) FROM request_logs WHERE event = 'webhook_failed' AND call_id = ?", [$callC]) === 6);

// =================== 4) no webhook_url -> skipped, stays pending =============
$clientB = $mkClient(null);
[$callD] = $mkCallDelivery($clientB);
[$wCode] = $exchange('cli/webhooks.php', null);
$check('CLI exits 0 on skipped rows', $wCode === 0);
$d = $deliveryRow($callD);
$check('delivery without URL untouched', ($d['state'] ?? '') === 'pending' && (int)($d['attempt'] ?? 0) === 0,
    'state=' . (string)($d['state'] ?? '-'));

// --------------------------- final self-clean -------------------------------
fclose($server);
if (is_file(Filesystem::absoluteFromRelative($resultRel))) {
    @unlink(Filesystem::absoluteFromRelative($resultRel));
}
$wipe();
$check('fixtures removed', (int)$db->value("SELECT COUNT(*) FROM requests WHERE code LIKE 'whk.%'") === 0
    && (int)$db->value("SELECT COUNT(*) FROM clients WHERE name LIKE 'webhook smk%'") === 0);

echo $failures === 0 ? '=== WEBHOOK SMOKE PASS ===' : "=== WEBHOOK SMOKE: {$failures} FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
