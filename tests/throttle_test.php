<?php
// Per-client submit throttle (client_rate_limits): the atomic counter under
// real cross-process concurrency, the 429 contract over HTTP, and the
// fixed-window reset. Usage: php tests\throttle_test.php
//
// The cap is deliberately 2 (env, read when config.php loads) so the window
// arithmetic stays visible; spawned children inherit it.

// Before bootstrap: Config reads the environment while the file loads.
putenv('GATEWAY_CLIENT_SUBMIT_MAX=2');

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

// ============ self-invoked concurrency worker ==============================
// One process, one genuinely parallel run of the production throttle. It prints
// nothing when the submit is allowed; the 429 path writes its JSON body and
// exits. php -S cannot fork on Windows, so hammering from separate processes is
// what actually overlaps the critical section the old log-count read did.
if (PHP_SAPI === 'cli' && isset($argv[1]) && str_starts_with((string)$argv[1], '--hammer=')) {
    $rm = new \ReflectionMethod(\Api\Submit::class, 'enforceClientSubmitRate');
    $rm->invoke(null, (int)substr((string)$argv[1], strlen('--hammer=')), (int)$argv[2], '127.0.0.1');
    exit(0);
}

$db = db();
$run = substr(\Core\Str::hex(4), 0, 8);
$WINDOW = max(1, (int)\Core\Config::get('limits.client_submit_window_seconds', 60));
$CAP = 2;

// ----------------- fixtures (run-scoped names, self-cleaning) -----------------
$clientIds = [];
$requestIds = [];
$connectionIds = [];
// FK-safe order: rate limits -> files/deliveries -> calls -> executions ->
// permissions -> logs -> requests -> connections -> clients.
$cleanup = static function () use ($db, &$clientIds, &$requestIds, &$connectionIds): void {
    $in = static fn (array $ids): string => implode(',', array_map('intval', $ids));
    if ($requestIds !== []) {
        $r = $in($requestIds);
        $db->execute("DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE request_id IN ($r))");
        $db->execute("DELETE FROM calls WHERE request_id IN ($r)");
        $db->execute("DELETE FROM executions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM client_request_permissions WHERE request_id IN ($r)");
        $db->execute("DELETE FROM request_logs WHERE request_id IN ($r)");
        $db->execute("DELETE FROM requests WHERE id IN ($r)");
    }
    if ($clientIds !== []) {
        $c = $in($clientIds);
        $db->execute("DELETE FROM client_rate_limits WHERE client_id IN ($c)");
        $db->execute("DELETE FROM calls WHERE client_id IN ($c)");
        $db->execute("DELETE FROM client_request_permissions WHERE client_id IN ($c)");
        $db->execute("DELETE FROM request_logs WHERE client_id IN ($c)");
        $db->execute("DELETE FROM clients WHERE id IN ($c)");
    }
    if ($connectionIds !== []) {
        $db->execute('DELETE FROM connections WHERE id IN (' . $in($connectionIds) . ')');
    }
};

$now = now_str();
$db->execute(
    'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted, max_concurrency, is_active, created_at)
     VALUES (?, ?, ?, NULL, ?, ?, ?, 2, 1, ?)',
    ["throttle {$run} mariadb", 'mysql', '127.0.0.1', 'mysql', 'root', \Services\Encryption::encrypt(''), $now]
);
$connId = $db->insertId();
$connectionIds[] = $connId;

$addRequest = static function (string $code) use ($db, $connId, $now): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds, delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, 15, 30, 1000, 1, ?)',
        [$code, $code, 'SELECT 1 AS ping', $connId, 'delayed', $now]
    );
    return $db->insertId();
};
$mkClient = static function (string $name) use ($db, $now, &$clientIds): array {
    $key = 'gw_throttle_' . \Core\Str::hex(16);
    $db->execute(
        'INSERT INTO clients (name, type, api_key_hash, is_active, created_at) VALUES (?, ?, ?, 1, ?)',
        [$name, 'agent', hash('sha256', $key), $now]
    );
    $clientIds[] = $db->insertId();
    return ['id' => (int)$db->insertId(), 'key' => $key];
};

$pass = 0;
$total = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$total): void {
    $total++;
    $pass += $ok ? 1 : 0;
    echo str_pad($ok ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
};

register_shutdown_function($cleanup);

// ======================= pass 1: parallel counter ===========================
$hReq = $addRequest("thk{$run}.hammer");
$requestIds[] = $hReq;
$h = $mkClient("throttle {$run} hammer");
$hId = (int)$h['id'];
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$hId, $hReq, $now]);

$children = [];
for ($i = 0; $i < 10; $i++) {
    $proc = proc_open(['php', __FILE__, '--hammer=' . $hId, (string)$hReq],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
    if (is_resource($proc)) {
        $children[] = ['proc' => $proc, 'pipes' => $pipes];
    }
}
// Spawned first, then drained: reading a child's stdout before the others have
// started would serialize them.
$accepted = 0;
$rejected = 0;
$crashed = 0;
foreach ($children as $child) {
    $stdout = (string)stream_get_contents($child['pipes'][1]);
    $stderr = (string)stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['proc']);
    if ($code !== 0 || $stderr !== '') {
        $crashed++;
        $check('hammer child exits clean', false, "code={$code} " . trim(substr($stderr, 0, 160)));
        continue;
    }
    str_contains($stdout, 'rate_limited') ? $rejected++ : $accepted++;
}
$hits = (int)$db->value('SELECT hits FROM client_rate_limits WHERE client_id = ?', [$hId]);
$check('parallel submits never slip past the cap', $accepted === $CAP && $crashed === 0,
    "accepted={$accepted} rejected={$rejected} children=" . count($children));
$check('every attempt, refused included, is counted', $rejected === count($children) - $CAP && $hits === count($children),
    "hits={$hits}");
$check('rate_limited logged once per refusal',
    (int)$db->value("SELECT COUNT(*) FROM request_logs WHERE client_id = ? AND event = 'rate_limited'", [$hId]) === $rejected);

// ======================= HTTP harness =======================================
// Random ports, never reused: a stale or sibling server must not answer.
$ports = [];
$servers = [];
for ($i = 0; $i < 3; $i++) {
    $port = rand(20000, 60000);
    while (in_array($port, $ports, true)) {
        $port = rand(20000, 60000);
    }
    $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', 'public', 'tests/dev_router.php'],
        [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        fwrite(STDERR, 'cannot start php -S' . PHP_EOL);
        exit(2);
    }
    $servers[] = $proc;
    $ports[] = $port;
}
register_shutdown_function(static function () use ($servers): void {
    foreach ($servers as $proc) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
usleep(600000);

/** One POST; returns [status, body, header block]. */
$api = static function (string $code, string $key) use ($ports): array {
    $ch = curl_init('http://127.0.0.1:' . $ports[0] . '/api/v1/request/' . $code);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
    ]);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $cut = strpos($raw, "\r\n\r\n");
    return [$status, substr($raw, $cut + 4), substr($raw, 0, $cut)];
};

/** $n simultaneous submits, spread round-robin over the dev servers. */
$parallel = static function (string $code, string $key, int $n) use ($ports): array {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init('http://127.0.0.1:' . $ports[$i % count($ports)] . '/api/v1/request/' . $code);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        ]);
        $handles[] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.05);
        }
    } while ($running && $status === CURLM_OK);
    $codes = [];
    foreach ($handles as $ch) {
        $codes[] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $codes;
};

// ============ pass 2: parallel HTTP submits =================================
$pCode = "thk{$run}.http";
$pReq = $addRequest($pCode);
$requestIds[] = $pReq;
$P = $mkClient("throttle {$run} parallel");
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$P['id'], $pReq, $now]);

$codes = $parallel($pCode, (string)$P['key'], 8);
$ok200 = count(array_filter($codes, static fn (int $c): bool => $c === 200));
$ok429 = count(array_filter($codes, static fn (int $c): bool => $c === 429));
$check('parallel HTTP submits: exactly cap accepted', $ok200 === $CAP, implode(',', $codes));
$check('parallel HTTP submits: the rest get 429', $ok429 === count($codes) - $CAP, implode(',', $codes));
$check('a refused submit creates no call',
    (int)$db->value('SELECT COUNT(*) FROM calls WHERE client_id = ?', [$P['id']]) === $CAP);

// ============ pass 3: the 429 contract ======================================
$sCode = "thk{$run}.seed";
$sReq = $addRequest($sCode);
$requestIds[] = $sReq;
$S = $mkClient("throttle {$run} seed");
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$S['id'], $sReq, $now]);

// Window already 45s old and at the cap: Retry-After must be the seconds left,
// not the configured window.
$db->execute('INSERT INTO client_rate_limits (client_id, window_start, hits) VALUES (?, DATE_SUB(NOW(), INTERVAL 45 SECOND), ?)',
    [$S['id'], $CAP]);
[$status, $body, $headers] = $api($sCode, (string)$S['key']);
$ra = preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m) === 1 ? (int)$m[1] : -1;
$check('cap reached -> 429 rate_limited', $status === 429
    && (string)(json_decode($body, true)['error']['code'] ?? '') === 'rate_limited',
    "status={$status} " . substr($body, 0, 120));
$check('Retry-After carries the real remaining window', $ra >= 10 && $ra <= 30, "retry-after={$ra} window={$WINDOW}");
$check('refused submit is logged once', $db->value(
    "SELECT COUNT(*) FROM request_logs WHERE client_id = ? AND event = 'rate_limited'", [$S['id']]) == 1);

// Window elapsed: the counter resets and the submit goes through again.
$db->execute('UPDATE client_rate_limits SET hits = 9, window_start = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE client_id = ?',
    [$WINDOW + 5, $S['id']]);
[$status, $body] = $api($sCode, (string)$S['key']);
$hits = (int)$db->value('SELECT hits FROM client_rate_limits WHERE client_id = ?', [$S['id']]);
$check('expired window -> submit accepted again', $status === 200
    && (string)(json_decode($body, true)['status'] ?? '') === 'scheduled', "status={$status} " . substr($body, 0, 120));
$check('the new window restarts the counter at 1', $hits === 1);

// A second client is untouched by the first one's exhausted window.
$qCode = "thk{$run}.other";
$qReq = $addRequest($qCode);
$requestIds[] = $qReq;
$Q = $mkClient("throttle {$run} other");
$db->execute('INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)', [$Q['id'], $qReq, $now]);
[$status] = $api($qCode, (string)$Q['key']);
$check('the throttle is per client', $status === 200, "status={$status}");

echo PHP_EOL, "throttle_test: {$pass}/{$total} checks passed", PHP_EOL;
exit($pass === $total ? 0 : 1);
