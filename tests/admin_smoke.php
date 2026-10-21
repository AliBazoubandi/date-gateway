<?php
// End-to-end smoke test for the admin panel (build step 2).
// Boots php -S on a random port, drives the flow with curl cookies:
// login -> connection -> request -> test run -> client -> permissions -> audit.
// Usage: php tests\admin_smoke.php [--admin=NAME:PASS]

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

// Random port: a stale or sibling server must never answer instead of us.
$port = rand(20000, 60000);
$root = BASE_PATH;

// Per-run fixtures: every name carries the random run id and the shutdown
// cleanup removes exactly this run's rows — a failed run leaves nothing
// behind (no leftover admin user, no grants, no audit rows, no login_attempts
// wipe: real data is never matched or touched).
$run = substr(\Core\Str::hex(4), 0, 8);
$adminName = "smoke_admin_{$run}";
$ghostName = "smoke_ghost_{$run}";
$connName = "smk {$run} mariadb";
$code = "smk{$run}.ping";
// [$code, $body] = http(...) reuses $code for the status; keep a stable alias.
$reqCode = $code;
$clientName = "smk {$run} agent";

$db = db();
$adminId = 0;
$clientId = 0;
$requestId = 0;
$connectionId = 0;
$cleanup = static function () use ($db, &$adminId, &$clientId, &$requestId, &$connectionId, $adminName, $ghostName): void {
    if ($requestId > 0) {
        $db->execute('DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE request_id = ?)', [$requestId]);
        $db->execute('DELETE FROM calls WHERE request_id = ?', [$requestId]);
        $db->execute('DELETE FROM executions WHERE request_id = ?', [$requestId]);
        $db->execute('DELETE FROM client_request_permissions WHERE request_id = ?', [$requestId]);
        $db->execute('DELETE FROM request_logs WHERE request_id = ?', [$requestId]);
        $db->execute('DELETE FROM requests WHERE id = ?', [$requestId]);
    }
    if ($clientId > 0) {
        $db->execute('DELETE FROM webhook_deliveries WHERE call_id IN (SELECT id FROM calls WHERE client_id = ?)', [$clientId]);
        $db->execute('DELETE FROM calls WHERE client_id = ?', [$clientId]);
        $db->execute('DELETE FROM client_request_permissions WHERE client_id = ?', [$clientId]);
        $db->execute('DELETE FROM request_logs WHERE client_id = ?', [$clientId]);
        $db->execute('DELETE FROM clients WHERE id = ?', [$clientId]);
    }
    if ($connectionId > 0) {
        $db->execute('DELETE FROM connections WHERE id = ?', [$connectionId]);
    }
    if ($adminId > 0) {
        // audit rows of the smoke admin go with the admin itself
        $db->execute('DELETE FROM admin_audit_log WHERE admin_user_id = ?', [$adminId]);
        $db->execute('DELETE FROM admin_users WHERE id = ?', [$adminId]);
    }
    // Authentication rows for an account that no longer exists (the unknown
    // username probe, and cli\create_admin.php) carry no admin_user_id, so they
    // are matched by their own entity_name instead.
    $db->execute('DELETE FROM admin_audit_log WHERE entity_type = ? AND entity_name IN (?, ?)',
        ['admin', $adminName, $ghostName]);
};
register_shutdown_function($cleanup);
chdir($root);

// Sessions for the child server live in the run's own storage, not in the
// global php.ini session.save_path. A locked-down host (the production shape)
// has a php.ini save path the CLI user cannot write: session_start() then fails
// and every POST answers 419, which looks like an application failure but is a
// harness one. The production Session class is unchanged - this only pins the
// save path for the server this test starts.
$sessionsDir = \Core\Filesystem::storageDir() . '/sessions';
if (!is_dir($sessionsDir) && !@mkdir($sessionsDir, 0770, true) && !is_dir($sessionsDir)) {
    fwrite(STDERR, "cannot create the test session directory {$sessionsDir}\n");
    exit(2);
}
// A real write probe, not is_writable(): the stat-based answer is exactly what
// fails on a tightened temp dir/ACL, which is the environment this harness
// change exists for.
$probe = $sessionsDir . '/probe.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (@file_put_contents($probe, 'x') === false || !@unlink($probe)) {
    fwrite(STDERR, "test session directory is not writable: {$sessionsDir}\n");
    exit(2);
}
// Pass the LONG path to the child: PHP's `-d` handling mangles a value that
// contains `~` (an 8.3 short name such as C:\Users\ALIBAZ~1) and the child then
// opens sessions in a truncated directory. realpath() expands the short name.
$sessionsDir = realpath($sessionsDir) ?: $sessionsDir;
if (str_contains($sessionsDir, '~')) {
    fwrite(STDERR, "cannot pass a session path containing '~' to the child: {$sessionsDir}\n");
    exit(2);
}
// Array command form: no cmd.exe wrapper, so proc_terminate() really kills
// php -S (string form orphans the server, which then wedges later runs).
$proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionsDir,
        '-S', '127.0.0.1:' . $port, '-t', 'public', 'tests/dev_router.php'],
    [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "cannot start php -S\n");
    exit(2);
}
register_shutdown_function(static function () use ($proc): void {
    proc_terminate($proc);
    proc_close($proc);
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

// Same GET as http(), but the response headers come back instead of the body.
function headers(string $path): array {
    $ch = curl_init('http://127.0.0.1:' . $GLOBALS['port'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $GLOBALS['cookie'],
        CURLOPT_COOKIEJAR => $GLOBALS['cookie'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $out = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, substr($out, 0, $size)];
}

// The session id curl is sending, read back from the cookie jar. Used to age
// the session file directly instead of waiting out the idle window.
function sid_from_jar(): string {
    $name = (string)\Core\Config::get('admin.session_name', 'datagateway_admin');
    $lines = file($GLOBALS['cookie'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last = '';
    foreach ($lines === false ? [] : $lines as $line) {
        $line = (string)preg_replace('/^#HttpOnly_/', '', $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $f = explode("\t", $line);
        if (count($f) >= 7 && $f[5] === $name && strlen($f[6]) >= 20) {
            $last = trim($f[6], '"');
        }
    }
    return $last;
}

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo str_pad($ok ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
};

[$code, $body] = http('GET', '/admin/login');
$check('login page 200 + CSRF', $code === 200 && str_contains($body, '_csrf'));

shell_exec('php "' . $root . '/cli/create_admin.php" --username=' . $adminName . ' --password=test!pass123');
$adminId = (int)db()->value('SELECT id FROM admin_users WHERE username = ?', [$adminName]);

$token = csrf($body);
[$code, $body] = http('POST', '/admin/login', [
    '_csrf' => $token, 'username' => $adminName, 'password' => 'wrong-pass-1',
]);
$check('wrong password rejected', str_contains($body, 'Wrong username or password') || str_contains($body, 'Locked'));

[$code, $body] = http('GET', '/admin/login');
$check('login page again', $code === 200);
[$code, $body] = http('POST', '/admin/login', [
    '_csrf' => csrf($body), 'username' => $adminName, 'password' => 'test!pass123',
]);
$check('login succeeds -> dashboard', str_contains($body, 'Dashboard'));

// Connection CRUD ---------------------------------------------------------
[$code, $body] = http('GET', '/admin/connections/new');
$check('connections/new 200', $code === 200);
$token = csrf($body);
[$code, $body] = http('POST', '/admin/connections', [
    '_csrf' => $token, 'id' => 0, 'name' => $connName, 'db_type' => 'mysql',
    'host' => '127.0.0.1', 'port' => '', 'db_name' => 'mysql', 'username' => 'root',
    'password' => '', 'max_concurrency' => '', 'is_active' => 1,
]);
[$code, $body] = http('GET', '/admin/connections');
$check('connection created + listed', str_contains($body, $connName));

// Request CRUD + test run -------------------------------------------------
[$code, $body] = http('GET', '/admin/requests/new');
$check('requests/new 200', $code === 200);
$token = csrf($body);
$connId = db()->value('SELECT id FROM connections WHERE name = ?', [$connName]);
$connectionId = (int)$connId;
[$code, $body] = http('POST', '/admin/requests', [
    '_csrf' => $token, 'id' => 0,
    'code' => $code, 'name' => 'Smoke ping', 'description' => 'smoke',
    'sql_text' => 'SELECT 1 AS ping, SLEEP(0.02) AS settle', 'connection_id' => $connId,
    'response_mode' => 'delayed', 'delay_minutes' => 0, 'ttl_minutes' => 15,
    'timeout_seconds' => 30, 'max_rows' => 1000, 'is_active' => 1,
]);
[$code, $body] = http('GET', '/admin/requests');
$check('request created + listed', str_contains($body, $code) && str_contains($body, 'Delayed'));
$reqId = (int)db()->value('SELECT id FROM requests WHERE code = ?', [$code]);
$requestId = $reqId;

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
    '_csrf' => $token, 'id' => $reqId, 'code' => $code, 'name' => 'Smoke ping',
    'description' => 'smoke', 'sql_text' => 'SELECT 1 AS ping, SLEEP(0.02) AS settle',
    'connection_id' => $connId, 'response_mode' => 'instant',
    'instant_cache_seconds' => 999999, 'delay_minutes' => 0, 'ttl_minutes' => 15,
    'timeout_seconds' => 999999, 'max_rows' => 99999999, 'is_active' => 1,
]);
$rowReq = db()->one('SELECT response_mode, timeout_seconds, max_rows, instant_cache_seconds FROM requests WHERE id = ?', [$reqId]);
$check('instant clamped to sane limits',
    $rowReq !== null && $rowReq['response_mode'] === 'instant'
    && (int)$rowReq['timeout_seconds'] <= (int)\Core\Config::get('limits.instant.max_timeout_seconds', 60)
    && (int)$rowReq['max_rows'] <= (int)\Core\Config::get('limits.instant.max_rows', 20000),
    json_encode($rowReq, JSON_UNESCAPED_SLASHES));
$cacheBound = min(86400, \Services\InstantExecutor::resultTtlMinutes() * 60);
$check('cache window clamped to the result file lifetime',
    $rowReq !== null && (int)$rowReq['instant_cache_seconds'] === $cacheBound,
    'bound ' . $cacheBound . ' s, stored ' . (int)($rowReq['instant_cache_seconds'] ?? -1));

[$code, $body] = http('GET', '/admin/requests');
$check('badge shows Instant', str_contains($body, 'Instant'));

// connection_id is a POST-only field: a query-string value used to win when
// the body omitted it, so a link could repoint a saved request at another
// connection while the form displayed the safe one.
[$code, $body] = http('GET', '/admin/requests/' . $reqId);
$token = csrf($body);
[$code, $body] = http('POST', '/admin/requests?connection_id=' . $connectionId, [
    '_csrf' => $token, 'id' => $reqId, 'code' => $reqCode, 'name' => 'Smoke ping',
    'description' => 'smoke', 'sql_text' => 'SELECT 1 AS ping',
    'response_mode' => 'delayed', 'delay_minutes' => 0, 'ttl_minutes' => 15,
    'timeout_seconds' => 30, 'max_rows' => 1000, 'is_active' => 1,
]);
$check('query-string connection_id is ignored',
    str_contains($body, 'Pick a connection')
    && (int)db()->value('SELECT connection_id FROM requests WHERE id = ?', [$reqId]) === $connectionId,
    'code=' . $code);

// Client with API key shown once + permissions ----------------------------
[$code, $body] = http('GET', '/admin/clients/new');
$token = csrf($body);
[$code, $body] = http('POST', '/admin/clients', [
    '_csrf' => $token, 'id' => 0, 'name' => $clientName, 'type' => 'agent',
    'webhook_url' => '', 'webhook_secret' => '', 'is_active' => 1,
]);
$hasKey = preg_match('/<code class="key">([0-9a-z_]+)<\/code>/', $body, $km) === 1;
$apiKey = $km[1] ?? '';
$check('api key revealed once', $hasKey && strlen($apiKey) >= 40);
$cliId = (int)db()->value('SELECT id FROM clients WHERE name = ?', [$clientName]);
$clientId = $cliId;
$check('only the hash is stored',
    strlen((string)db()->value('SELECT api_key_hash FROM clients WHERE id = ?', [$cliId])) === 64);
$check('peppered API-key hash stored alongside the SHA-256',
    strlen((string)db()->value('SELECT api_key_hash_hmac FROM clients WHERE id = ?', [$cliId])) === 64);

// The create left the webhook field empty, so the panel auto-generated a
// secret, showed it exactly once and stored it ENCRYPTED (AES-256-GCM).
$revealedSecret = '';
if (preg_match_all('/<code class="key">([0-9a-z_]+)<\/code>/', $body, $kmAll) >= 2) {
    $revealedSecret = (string)($kmAll[1][1] ?? '');
}
$check('auto-generated webhook secret revealed once',
    $revealedSecret !== '' && $revealedSecret !== $apiKey);
$secretBlob = (string)db()->value('SELECT webhook_secret_enc FROM clients WHERE id = ?', [$cliId]);
$check('webhook secret stored encrypted at rest', str_starts_with($secretBlob, 'gcm:v1:'));
$check('encrypted webhook secret decrypts to the revealed value',
    $revealedSecret !== '' && \Services\Encryption::decrypt($secretBlob) === $revealedSecret);

[$code, $body] = http('GET', '/admin/clients/' . $cliId);
$check('permissions editor lists request', str_contains($body, $code));
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
// stored (encrypted) secret must survive untouched — verified directly: the
// masked placeholder is rendered, and the blob re-read after the accepted
// save below is byte-identical.
$check('secret field renders as masked', str_contains($body, 'leave empty to keep'));

// Webhook URL policy on save (Services\WebhookUrl) ------------------------
// 169.254.169.254 is refused whatever webhook.allow_* says. The loopback
// receiver below is accepted only because the runner turns on
// webhook.allow_loopback/allow_http for the suites, which is exactly what
// webhook_smoke's local receiver needs.
[$code, $body] = http('POST', '/admin/clients', [
    '_csrf' => csrf($body), 'id' => $cliId, 'name' => $clientName, 'type' => 'agent',
    'webhook_url' => 'http://169.254.169.254/latest/meta-data/', 'webhook_secret' => '', 'is_active' => 1,
]);
$check('blocked webhook URL refused on save',
    str_contains($body, 'blocked')
    && (string)db()->value('SELECT webhook_url FROM clients WHERE id = ?', [$cliId]) === '',
    substr(strip_tags((string)$body), 0, 160));
[$code, $body] = http('POST', '/admin/clients', [
    '_csrf' => csrf($body), 'id' => $cliId, 'name' => $clientName, 'type' => 'agent',
    'webhook_url' => 'http://127.0.0.1:9/hook', 'webhook_secret' => '', 'is_active' => 1,
]);
$check('policy-allowed webhook URL saved',
    (string)db()->value('SELECT webhook_url FROM clients WHERE id = ?', [$cliId]) === 'http://127.0.0.1:9/hook');
$check('empty webhook field keeps the encrypted secret',
    (string)db()->value('SELECT webhook_secret_enc FROM clients WHERE id = ?', [$cliId]) === $secretBlob);

// Key reveal page shows the same key only when just regenerated -----------
[$code, $body] = http('POST', '/admin/clients/regen-key', ['_csrf' => $token, 'id' => $cliId]);
$check('regen key once-page', preg_match('/The previous key stopped working/', $body) === 1);
$newKey = $km[0] ?? '';
preg_match('/<code class="key">([0-9a-z_]+)<\/code>/', $body, $km2);
$check('new key != old key', ($km2[1] ?? '') !== $apiKey);

// Webhook secret rotation: the new secret is shown exactly once and is the
// only value ever written to the encrypted column.
[$code, $body] = http('POST', '/admin/clients/gen-secret', ['_csrf' => $token, 'id' => $cliId]);
preg_match('/<code class="key">([0-9a-z_]+)<\/code>/', $body, $sm);
$rotatedSecret = $sm[1] ?? '';
$rotatedBlob = (string)db()->value('SELECT webhook_secret_enc FROM clients WHERE id = ?', [$cliId]);
$check('webhook secret rotated and shown exactly once',
    $rotatedSecret !== '' && $rotatedSecret !== $revealedSecret
    && strlen($rotatedSecret) === strlen($revealedSecret));
$check('rotated secret stored encrypted and decrypts to the new value',
    str_starts_with($rotatedBlob, 'gcm:v1:')
    && \Services\Encryption::decrypt($rotatedBlob) === $rotatedSecret);

// CSRF rejection ----------------------------------------------------------
$ch = curl_init('http://127.0.0.1:' . $port . '/admin/requests/delete');
curl_setopt_array($ch, [CURLOPT_POSTFIELDS => http_build_query(['id' => $reqId]),
    CURLOPT_COOKIEFILE => $cookie, CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
$check('POST without CSRF -> 419', curl_getinfo($ch, CURLINFO_RESPONSE_CODE) == 419);
curl_close($ch);

// Logout requires POST + CSRF; a plain GET must not log anyone out -----------
// Discriminator: the logout form (action="/admin/logout") renders only when
// authenticated ($admin !== null in header.php); "Admin login" only on the
// login page. The nav "Dashboard" link appears on both, so it is not usable.
[, $body] = http('GET', '/admin/logout');
$check('GET logout does not log out (link CSRF safe)', str_contains($body, 'action="/admin/logout"'));

$ch = curl_init('http://127.0.0.1:' . $port . '/admin/logout');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
$check('POST logout without CSRF -> 419', curl_getinfo($ch, CURLINFO_RESPONSE_CODE) == 419);
curl_close($ch);
[, $body] = http('GET', '/admin');
$check('still logged in after CSRF-less logout POST', str_contains($body, 'action="/admin/logout"'));

[, $body] = http('POST', '/admin/logout', ['_csrf' => csrf($body)]);
$check('POST logout with CSRF lands on login page', str_contains($body, 'Admin login') && !str_contains($body, 'action="/admin/logout"'));
[, $body] = http('GET', '/admin');
$check('session is gone after CSRF logout', str_contains($body, 'Admin login') && !str_contains($body, 'action="/admin/logout"'));

// Session hardening: no-store, idle timeout, password rotation ----------------
// Signing out is audited (manually here, by idleness below).
$check('manual logout audited',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'admin' AND action = 'logout'
        AND admin_user_id = ? AND after_json LIKE ?", [$adminId, '%manual%']) >= 1);

[, $body] = http('GET', '/admin/login');
[$code, $body] = http('POST', '/admin/login', [
    '_csrf' => csrf($body), 'username' => $adminName, 'password' => 'test!pass123',
]);
$check('logged in again for the session checks', str_contains($body, 'Dashboard'));
$check('successful logins audited',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'admin' AND action = 'login'
        AND admin_user_id = ?", [$adminId]) >= 2);

[$hcode, $hdr] = headers('/admin');
$check('admin pages send Cache-Control: no-store',
    $hcode === 200 && stripos($hdr, 'Cache-Control: no-store') !== false,
    trim(preg_replace('/\r?\n/', ' | ', $hdr) ?? ''));
[, $hdr2] = headers('/admin/login');
$check('the login page is not cacheable either', stripos($hdr2, 'Cache-Control: no-store') !== false);

// ---- idle timeout: age the session's own last_seen instead of waiting 30 min.
$sid = sid_from_jar();
// The child server was started with its save_path pinned to this run's
// storage, so that is where its session file is - never the global php.ini one.
$savePath = $sessionsDir;
$sessFile = $savePath . '/sess_' . $sid;
$check('session file of this run located', $sid !== '' && is_file($sessFile), $sessFile);
$aged = false;
if ($sid !== '' && is_file($sessFile)) {
    $data = (string)file_get_contents($sessFile);
    $replaced = preg_replace('/last_seen\|i:\d+;/', 'last_seen|i:' . (time() - 4000) . ';', $data, 1);
    $aged = is_string($replaced) && $replaced !== $data && file_put_contents($sessFile, $replaced) !== false;
}
$check('session aged past the idle window', $aged);
[, $body] = http('GET', '/admin/connections');
$check('idle session is logged out with an explanation',
    str_contains($body, 'Your session expired') && !str_contains($body, 'action="/admin/logout"'),
    substr(strip_tags($body), 0, 120));
$check('idle logout audited',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'admin' AND action = 'logout'
        AND admin_user_id = ? AND after_json LIKE ?", [$adminId, '%idle%']) >= 1);

// ---- a replaced password must kill the session that was issued before it.
[, $body] = http('GET', '/admin/login');
[$code, $body] = http('POST', '/admin/login', [
    '_csrf' => csrf($body), 'username' => $adminName, 'password' => 'test!pass123',
]);
$check('logged in after the idle logout', str_contains($body, 'Dashboard'));
$beforeVersion = (int)db()->value('SELECT session_version FROM admin_users WHERE id = ?', [$adminId]);
shell_exec('php "' . $root . '/cli/create_admin.php" --username=' . $adminName . ' --password=test!pass123');
$afterVersion = (int)db()->value('SELECT session_version FROM admin_users WHERE id = ?', [$adminId]);
$check('replacing the password advances session_version', $afterVersion === $beforeVersion + 1,
    $beforeVersion . ' -> ' . $afterVersion);
[, $body] = http('GET', '/admin/connections');
$check('session issued before the password change is dead',
    str_contains($body, 'Admin login') && !str_contains($body, 'action="/admin/logout"')
    && !str_contains($body, 'Your session expired'), substr(strip_tags($body), 0, 120));
$check('password change audited with the new epoch',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'admin'
        AND action = 'password_change' AND entity_id = ? AND after_json LIKE ?",
        [$adminId, '%session_version%']) >= 1);
$check('admin account creation audited',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'admin'
        AND action = 'create' AND entity_name = ?", [$adminName]) >= 1);

// ---- refused sign-ins are audited, and never with the typed password.
[, $body] = http('GET', '/admin/login');
http('POST', '/admin/login', [
    '_csrf' => csrf($body), 'username' => $ghostName, 'password' => 'ghost!pass123x',
]);
$ghost = db()->one("SELECT admin_user_id, entity_name FROM admin_audit_log
    WHERE entity_type = 'admin' AND action = 'login_failed' AND entity_name = ?", [$ghostName]);
$check('unknown username probe audited without an admin id',
    $ghost !== null && $ghost['admin_user_id'] === null, json_encode($ghost));
$check('wrong password audited with a reason',
    (int)db()->value("SELECT COUNT(*) FROM admin_audit_log WHERE entity_type = 'admin'
        AND action = 'login_failed' AND entity_name = ? AND after_json LIKE ?",
        [$adminName, '%wrong_password%']) >= 1);
$leaks = [];
foreach (db()->rows("SELECT COALESCE(before_json, '') AS b, COALESCE(after_json, '') AS a
                     FROM admin_audit_log WHERE entity_type = 'admin'") as $r) {
    $blob = (string)$r['b'] . (string)$r['a'];
    if (strpos($blob, 'test!pass123') !== false || strpos($blob, 'ghost!pass123x') !== false
        || strpos($blob, '$2y$') !== false || stripos($blob, 'password_hash') !== false) {
        $leaks[] = $blob;
    }
}
$check('no admin audit row carries a password or a hash', $leaks === [], implode(' || ', $leaks));

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

echo $fail === 0 ? "=== ADMIN SMOKE PASS ({$pass} checks) ===" : "=== ADMIN SMOKE FAIL: {$fail} failed, {$pass} OK ===", PHP_EOL;
exit($fail === 0 ? 0 : 1);
