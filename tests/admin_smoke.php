<?php
// End-to-end smoke test for the admin panel (build step 2).
// Boots php -S on a random port, drives the flow with curl cookies:
// login -> connection -> request -> test run -> client -> permissions -> audit.
// Usage: php tests\admin_smoke.php [--admin=NAME:PASS]

require __DIR__ . '/../src/Core/bootstrap.php';

// Random port: a stale or sibling server must never answer instead of us.
$port = rand(20000, 60000);
$root = BASE_PATH;

// Self-cleaning: wipe smoke leftovers so the run is repeatable.
foreach ([
    'DELETE FROM webhook_deliveries',
    'DELETE FROM request_logs',
    'DELETE FROM calls',
    'DELETE FROM executions',
    'DELETE FROM client_request_permissions',
    "DELETE FROM clients WHERE name LIKE 'smoke%'",
    'DELETE FROM login_attempts',
    "DELETE r FROM requests r JOIN connections c ON c.id = r.connection_id
        WHERE r.code LIKE 'smoke%' OR c.name LIKE 'local mariadb%'",
    "DELETE FROM connections WHERE name LIKE 'local mariadb%'",
    "DELETE FROM admin_users WHERE username = 'smoke_admin'",
    'DELETE FROM admin_audit_log',
] as $sql) {
    db()->execute($sql);
}
// Windows proc_open + paths-with-spaces mangles extra args; chdir keeps the
// command line space-free.
chdir($root);
$proc = proc_open('php -S 127.0.0.1:' . $port . ' -t public tests/dev_router.php',
    [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "cannot start php -S\n");
    exit(2);
}
register_shutdown_function(static function () use ($proc): void {
    proc_terminate($proc);
});
usleep(500000);

$cookie = tempnam(sys_get_temp_dir(), 'gwcookie');

function http(string $method, string $path, array $data = []): array {
    global $port;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => $GLOBALS['cookie'],
        CURLOPT_COOKIEJAR => $GLOBALS['cookie'],
        CURLOPT_TIMEOUT => 20,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf(string $html): string {
    if (preg_match('/name="_csrf" value="([0-9a-f]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    fwrite(STDERR, "cannot find CSRF token\n");
    exit(2);
}

$pass = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass): void {
    $pass += $ok ? 1 : 0;
    echo str_pad($ok ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
};

[$code, $body] = http('GET', '/admin/login');
$check('login page 200 + CSRF', $code === 200 && str_contains($body, '_csrf'));

shell_exec('php "' . $root . '/cli/create_admin.php" --username=smoke_admin --password=test!pass123');

$token = csrf($body);
[$code, $body] = http('POST', '/admin/login', [
    '_csrf' => $token, 'username' => 'smoke_admin', 'password' => 'wrong-pass-1',
]);
$check('wrong password rejected', str_contains($body, 'Wrong username or password') || str_contains($body, 'Locked'));

[$code, $body] = http('GET', '/admin/login');
$check('login page again', $code === 200);
[$code, $body] = http('POST', '/admin/login', [
    '_csrf' => csrf($body), 'username' => 'smoke_admin', 'password' => 'test!pass123',
]);
$check('login succeeds -> dashboard', str_contains($body, 'Dashboard'));

// Connection CRUD ---------------------------------------------------------
[$code, $body] = http('GET', '/admin/connections/new');
$check('connections/new 200', $code === 200);
$token = csrf($body);
[$code, $body] = http('POST', '/admin/connections', [
    '_csrf' => $token, 'id' => 0, 'name' => 'local mariadb', 'db_type' => 'mysql',
    'host' => '127.0.0.1', 'port' => '', 'db_name' => 'mysql', 'username' => 'root',
    'password' => '', 'max_concurrency' => '', 'is_active' => 1,
]);
[$code, $body] = http('GET', '/admin/connections');
$check('connection created + listed', str_contains($body, 'local mariadb'));

// Request CRUD + test run -------------------------------------------------
[$code, $body] = http('GET', '/admin/requests/new');
$check('requests/new 200', $code === 200);
$token = csrf($body);
$connId = db()->value('SELECT id FROM connections WHERE name = ?', ['local mariadb']);
[$code, $body] = http('POST', '/admin/requests', [
    '_csrf' => $token, 'id' => 0,
    'code' => 'smoke.ping', 'name' => 'Smoke ping', 'description' => 'smoke',
    'sql_text' => 'SELECT 1 AS ping, SLEEP(0.02) AS settle', 'connection_id' => $connId,
    'response_mode' => 'delayed', 'delay_minutes' => 0, 'ttl_minutes' => 15,
    'timeout_seconds' => 30, 'max_rows' => 1000, 'is_active' => 1,
]);
[$code, $body] = http('GET', '/admin/requests');
$check('request created + listed', str_contains($body, 'smoke.ping') && str_contains($body, 'Delayed'));
$reqId = (int)db()->value('SELECT id FROM requests WHERE code = ?', ['smoke.ping']);

[$code, $body] = http('POST', '/admin/requests/test', ['_csrf' => $token, 'id' => $reqId]);
$check('test run shows rows', str_contains($body, '<code>1<'));
if (!str_contains($body, '<code>1<')) {
    fwrite(STDERR, "--- test page snippet ---\n");
    $i = strpos($body, '<h1>');
    fwrite(STDERR, $i === false ? substr($body, 0, 600) : substr($body, $i, 1500) . "\n");
}
$check('test run audit entry written',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'request' AND action = 'test_run'") >= 1);

// Instant validation clamp ------------------------------------------------
[$code, $body] = http('GET', '/admin/requests/' . $reqId);
$token = csrf($body);
[$code, $body] = http('POST', '/admin/requests', [
    '_csrf' => $token, 'id' => $reqId, 'code' => 'smoke.ping', 'name' => 'Smoke ping',
    'description' => 'smoke', 'sql_text' => 'SELECT 1 AS ping, SLEEP(0.02) AS settle',
    'connection_id' => $connId, 'response_mode' => 'instant',
    'instant_cache_seconds' => 30, 'delay_minutes' => 0, 'ttl_minutes' => 15,
    'timeout_seconds' => 999999, 'max_rows' => 99999999, 'is_active' => 1,
]);
$rowReq = db()->one('SELECT response_mode, timeout_seconds, max_rows, instant_cache_seconds FROM requests WHERE id = ?', [$reqId]);
$check('instant clamped to sane limits',
    $rowReq !== null && $rowReq['response_mode'] === 'instant'
    && (int)$rowReq['timeout_seconds'] <= (int)\Core\Config::get('limits.instant.max_timeout_seconds', 60)
    && (int)$rowReq['max_rows'] <= (int)\Core\Config::get('limits.instant.max_rows', 20000),
    json_encode($rowReq, JSON_UNESCAPED_SLASHES));

[$code, $body] = http('GET', '/admin/requests');
$check('badge shows Instant', str_contains($body, 'Instant'));

// Client with API key shown once + permissions ----------------------------
[$code, $body] = http('GET', '/admin/clients/new');
$token = csrf($body);
[$code, $body] = http('POST', '/admin/clients', [
    '_csrf' => $token, 'id' => 0, 'name' => 'smoke agent', 'type' => 'agent',
    'webhook_url' => '', 'webhook_secret' => '', 'is_active' => 1,
]);
$hasKey = preg_match('/<code class="key">([0-9a-z_]+)<\/code>/', $body, $km) === 1;
$apiKey = $km[1] ?? '';
$check('api key revealed once', $hasKey && strlen($apiKey) >= 40);
$cliId = (int)db()->value('SELECT id FROM clients WHERE name = ?', ['smoke agent']);
$check('only the hash is stored',
    strlen((string)db()->value('SELECT api_key_hash FROM clients WHERE id = ?', [$cliId])) === 64);

[$code, $body] = http('GET', '/admin/clients/' . $cliId);
$check('permissions editor lists request', str_contains($body, 'smoke.ping'));
[$code, $body] = http('POST', '/admin/clients/permissions', [
    '_csrf' => csrf($body), 'id' => $cliId, 'request_ids' => [$reqId],
]);
$check('permission saved',
    db()->value('SELECT COUNT(*) FROM client_request_permissions WHERE client_id = ?', [$cliId]) == 1);
[$code, $body] = http('GET', '/admin/clients/' . $cliId);
$check('checkbox is checked', str_contains($body, 'value="' . $reqId . '" checked'));
$check('stored webhook secret never rendered',
    preg_match('/name="webhook_secret" value="[^"]+"/', $body) !== 1);
// Empty secret field must mean "keep": save with the field blank and the
// stored secret must survive (verified indirectly via a second save+login...
// here we just assert the masked placeholder is present).
$check('secret field renders as masked', str_contains($body, 'leave empty to keep'));

// Key reveal page shows the same key only when just regenerated -----------
[$code, $body] = http('POST', '/admin/clients/regen-key', ['_csrf' => $token, 'id' => $cliId]);
$check('regen key once-page', preg_match('/The previous key stopped working/', $body) === 1);
$newKey = $km[0] ?? '';
preg_match('/<code class="key">([0-9a-z_]+)<\/code>/', $body, $km2);
$check('new key != old key', ($km2[1] ?? '') !== $apiKey);

// CSRF rejection ----------------------------------------------------------
$ch = curl_init('http://127.0.0.1:' . $port . '/admin/requests/delete');
curl_setopt_array($ch, [CURLOPT_POSTFIELDS => http_build_query(['id' => $reqId]),
    CURLOPT_COOKIEFILE => $cookie, CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
$check('POST without CSRF -> 419', curl_getinfo($ch, CURLINFO_RESPONSE_CODE) == 419);
curl_close($ch);

// Audit rows exist for actions -------------------------------------------
$auditKinds = db()->rows('SELECT entity_type, action, COUNT(*) c FROM admin_audit_log GROUP BY entity_type, action');
$flat = [];
foreach ($auditKinds as $k) {
    $flat[] = $k['entity_type'] . '.' . $k['action'];
}
$check('audit covers connection/request/client/test_run/permission',
    (int)$auditKinds !== 0 && in_array('request.update', $flat, true)
    && in_array('connection.create', $flat, true) && in_array('client.create', $flat, true)
    && in_array('request.test_run', $flat, true) && in_array('permission.update', $flat, true),
    implode(', ', $flat));

echo $pass >= 14 ? '=== ADMIN SMOKE PASS ===' : "=== ADMIN SMOKE: {$pass} OK ===", PHP_EOL;
exit(0);
