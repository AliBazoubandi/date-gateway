<?php
// ============================================================================
// tests/health_test.php - GET /health (README §4).
//
// Drives the endpoint over a real php -S server and asserts the four checks,
// the 200/503 verdict, the 405, and - because the endpoint is unauthenticated -
// that the body carries no deployment details. Each degraded branch runs in its
// own child server with one changed GATEWAY_* variable, the same way the other
// suites pin a policy. Nothing is mocked: the DB check pings the throwaway
// metadata DB, the writable check writes into the throwaway storage dir, and the
// heartbeat is the real worker_state row the pool writes.
//
// Usage: php tests\health_test.php   (needs the throwaway environment:
//        GATEWAY_TEST_MODE=1 + a *_test schema, e.g. via tests\run_all.php)
// ============================================================================

declare(strict_types=1);

use Core\Config;
use Core\Time;
use Services\WorkerState;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

$db = db();
$failures = 0;
$total = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures, &$total): void {
    $total++;
    echo str_pad($ok ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

// ----------------- dev servers + one request ---------------------------------
$baseEnv = [];
foreach ((array)getenv() as $name => $value) {
    $baseEnv[(string)$name] = (string)$value;
}
/** @var list<resource> $servers - every server this run started, killed on exit */
$servers = [];
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $proc) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
});

/**
 * Start `php -S` for one scenario: array command form (no cmd.exe wrapper, so
 * proc_terminate really kills the server) and an explicit environment that
 * differs from this run's in exactly the keys the scenario passes.
 *
 * @param array<string,string> $env
 */
function serve(array $env, array $baseEnv): array
{
    global $servers;
    $merged = $baseEnv;
    foreach ($env as $k => $v) {
        $merged[$k] = $v;
    }
    $port = rand(20000, 60000);
    $pipes = [];
    $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', 'public', 'tests/dev_router.php'],
        [1 => ['file', 'php://temp', 'w'], 2 => ['file', 'php://temp', 'w']], $pipes, BASE_PATH, $merged);
    if (!is_resource($proc)) {
        fwrite(STDERR, 'cannot start php -S' . PHP_EOL);
        exit(2);
    }
    $servers[] = $proc;
    // Wait for the listener rather than guessing a sleep: this run starts and
    // stops several servers, and a slow host would otherwise show up as a
    // connection error instead of a health failure.
    for ($i = 0; $i < 60; $i++) {
        usleep(100000);
        if (healthFetch($port)['code'] !== 0) {
            return [$port, $proc];
        }
    }
    fwrite(STDERR, 'php -S on port ' . $port . ' never answered' . PHP_EOL);
    exit(2);
}

/** @return array{code:int,body:string,type:string,headers:string} */
function healthFetch(int $port, string $method = 'GET'): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    $raw = (string)curl_exec($ch);
    $out = [
        'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => substr($raw, (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE)),
        'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'headers' => substr($raw, 0, (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE)),
    ];
    curl_close($ch);
    return $out;
}

/** The checks object of an answer, or [] when the body was not JSON. */
function checks(array $res): array
{
    $body = json_decode($res['body'], true);
    return (array)($body['checks'] ?? []);
}

function statusOf(array $res): ?string
{
    $body = json_decode($res['body'], true);
    return is_array($body) && array_key_exists('status', $body) ? (string)$body['status'] : null;
}

// ----------------- baseline: a heartbeat the pool would write ----------------
WorkerState::record(Time::now(), 0);
[$port] = serve([], $baseEnv);
$ok = healthFetch($port);
$c = checks($ok);
$check('healthy gateway answers 200', $ok['code'] === 200,
    'got ' . $ok['code'] . ' ' . substr($ok['body'], 0, 200));
$check('the answer is JSON', str_contains($ok['type'], 'application/json'), $ok['type']);
$check('status ok', statusOf($ok) === 'ok', (string)statusOf($ok));
$check('database reachable', ($c['database'] ?? null) === true);
$check('database carries no error', ($c['database_error'] ?? null) === null);
$check('storage writable', ($c['storage_writable'] ?? null) === true);
$check('storage space above the floor', ($c['storage_space'] ?? null) === true);
$check('worker recent', ($c['worker_recent'] ?? null) === true);
$age = $c['worker_last_pass_age_seconds'] ?? null;
$check('the heartbeat is seconds old', is_int($age) && $age <= 60, var_export($age, true));
$check('the pass reported no failures', ($c['worker_last_pass_failures'] ?? null) === 0);
$check('worker_last_pass_at is a Tehran ISO timestamp',
    (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+03:30$/', (string)($c['worker_last_pass_at'] ?? '')) === 1),
    (string)($c['worker_last_pass_at'] ?? ''));

// ----------------- what an unauthenticated probe must not learn --------------
foreach ([
    'the metadata database name' => (string)Config::get('metadata_db.database', ''),
    'the metadata user' => (string)Config::get('metadata_db.user', ''),
    'the metadata host' => (string)Config::get('metadata_db.host', ''),
    'the project root' => BASE_PATH,
    'the storage dir' => Core\Filesystem::storageDir(),
    'the master key' => (string)Config::get('security.master_key', ''),
] as $label => $value) {
    $check("the body does not contain {$label}",
        $value === '' || !str_contains($ok['body'], $value), $value === '' ? '' : $value);
}

// ----------------- only GET --------------------------------------------------
$post = healthFetch($port, 'POST');
$check('POST /health is refused', $post['code'] === 405, 'got ' . $post['code']);
$check('the refusal says which methods work',
    (bool)preg_match('/^Allow: GET\s*$/mi', $post['headers']), str_replace("\r\n", ' | ', trim($post['headers'])));
$check('the refusal is still JSON', str_contains($post['type'], 'application/json'), $post['type']);
$check('a 405 does not report a health verdict', statusOf($post) === null);

// ----------------- the worker check ------------------------------------------
$twoHoursAgo = Time::mysql(Time::nowDt()->modify('-2 hours'));
$db->execute('UPDATE worker_state SET last_pass_finished_at = ? WHERE id = 1', [$twoHoursAgo]);
$stale = healthFetch($port);
$c = checks($stale);
$check('a pool that stopped firing answers 503', $stale['code'] === 503, 'got ' . $stale['code']);
$check('status degraded', statusOf($stale) === 'degraded');
$check('worker_recent is the failing check', ($c['worker_recent'] ?? null) === false);
$staleAge = $c['worker_last_pass_age_seconds'] ?? null;
$check('the age is reported so a monitor can chart it',
    is_int($staleAge) && $staleAge >= 7100 && $staleAge <= 7300, var_export($staleAge, true));
$check('the other three checks still pass', ($c['database'] ?? null) === true
    && ($c['storage_writable'] ?? null) === true
    && ($c['storage_space'] ?? null) === true);

// worker.max_silence_seconds is read per request, not baked into the code.
[$knobPort] = serve(['GATEWAY_WORKER_MAX_SILENCE_SECONDS' => '86400'], $baseEnv);
$tolerant = healthFetch($knobPort);
$check('a wider worker.max_silence_seconds accepts the same row', $tolerant['code'] === 200,
    'got ' . $tolerant['code'] . ' ' . substr($tolerant['body'], 0, 200));

// No row at all: nothing has been recorded since installation.
$db->execute('DELETE FROM worker_state');
$none = healthFetch($port);
$c = checks($none);
$check('no heartbeat recorded answers 503', $none['code'] === 503, 'got ' . $none['code']);
$check('and says so with nulls, not a guess',
    ($c['worker_recent'] ?? null) === false
    && array_key_exists('worker_last_pass_at', $c) && $c['worker_last_pass_at'] === null
    && array_key_exists('worker_last_pass_age_seconds', $c) && $c['worker_last_pass_age_seconds'] === null
    && array_key_exists('worker_last_pass_failures', $c) && $c['worker_last_pass_failures'] === null,
    json_encode($c));

// A pass that had failures is still a live scheduler: informational only.
WorkerState::record(Time::now(), 3);
$withFailures = healthFetch($port);
$check('a pass that reported failures is still 200', $withFailures['code'] === 200,
    'got ' . $withFailures['code'] . ' ' . substr($withFailures['body'], 0, 200));
$check('and the failure count is visible',
    (int)(checks($withFailures)['worker_last_pass_failures'] ?? -1) === 3);

// One row, always id 1: a heartbeat must not become a log.
WorkerState::record(Time::now(), 0);
WorkerState::record(Time::now(), 1);
$check('recording a pass updates the row instead of appending',
    (int)$db->value('SELECT COUNT(*) FROM worker_state') === 1,
    (string)$db->value('SELECT COUNT(*) FROM worker_state'));
$check('the latest pass wins',
    (int)$db->value('SELECT last_pass_failures FROM worker_state WHERE id = 1') === 1);

// ----------------- the pool really writes it ---------------------------------
$db->execute('DELETE FROM worker_state');
$poolOut = (string)tempnam(sys_get_temp_dir(), 'gw_pool_');
$poolPipes = [];
$pool = proc_open([PHP_BINARY, 'cli/worker_pool.php'],
    [1 => ['file', $poolOut, 'w'], 2 => ['file', $poolOut, 'a']], $poolPipes, BASE_PATH, $baseEnv);
if (is_resource($pool)) {
    $poolCode = proc_close($pool);
    $poolText = (string)file_get_contents($poolOut);
} else {
    $poolCode = -1;
    $poolText = 'the pool did not start';
}
@unlink($poolOut);
$check('a real pool pass exits 0', $poolCode === 0, 'exit ' . $poolCode . ': ' . substr($poolText, 0, 200));
$after = WorkerState::lastPass();
$poolAge = WorkerState::ageSeconds((string)($after['last_pass_finished_at'] ?? ''));
$check('and records the heartbeat GET /health reads',
    $after !== null && $poolAge !== null && $poolAge < 60, json_encode($after));
$check('a clean pool pass records no failures', (int)($after['last_pass_failures'] ?? -1) === 0,
    json_encode($after));
$check('that pass then answers 200', healthFetch($port)['code'] === 200);

// ----------------- storage that cannot be written ----------------------------
// A path below a REGULAR file: no ACL trickery, and it fails the same way on
// Linux, which is what CI runs.
$blocker = (string)tempnam(sys_get_temp_dir(), 'gw_health_blocker_');
[$roPort] = serve(['GATEWAY_STORAGE_DIR' => $blocker . '/inside/storage'], $baseEnv);
$ro = healthFetch($roPort);
$c = checks($ro);
$check('storage that cannot be written answers 503', $ro['code'] === 503, 'got ' . $ro['code']);
$check('and names storage_writable as the failing check',
    ($c['storage_writable'] ?? null) === false && ($c['database'] ?? null) === true, json_encode($c));
$check('the unwritable path is never echoed', !str_contains($ro['body'], $blocker));

// ----------------- the free-space floor --------------------------------------
// 1 PiB: no volume passes this. The check is the same Services\StorageGuard
// decision a delayed execution applies, so the two cannot drift.
[$floodedPort] = serve(['GATEWAY_MIN_FREE_DISK_BYTES' => (string)(1024 ** 5)], $baseEnv);
$flooded = healthFetch($floodedPort);
$c = checks($flooded);
$check('a volume under limits.min_free_disk_bytes answers 503', $flooded['code'] === 503,
    'got ' . $flooded['code']);
$check('and names storage_space as the failing check',
    ($c['storage_space'] ?? null) === false && ($c['database'] ?? null) === true
    && ($c['storage_writable'] ?? null) === true, json_encode($c));

// ----------------- the metadata DB unreachable -------------------------------
$absentDb = (string)Config::get('metadata_db.database', '') . '_absent';
[$gonePort] = serve(['GATEWAY_DB_NAME' => $absentDb], $baseEnv);
$gone = healthFetch($gonePort);
$c = checks($gone);
$check('an unreachable metadata DB answers 503', $gone['code'] === 503, 'got ' . $gone['code']);
$check('the answer is still JSON, not a crash', str_contains($gone['type'], 'application/json'),
    $gone['type'] . ' ' . substr($gone['body'], 0, 120));
$check('and says the database check failed', ($c['database'] ?? null) === false);
$check('with a reason a human can read',
    is_string($c['database_error'] ?? null) && $c['database_error'] !== '', json_encode($c));
$check('mysqli details are not echoed',
    !str_contains(strtolower($gone['body']), 'mysqli') && !str_contains(strtolower($gone['body']), 'sqlstate'),
    substr($gone['body'], 0, 200));
$check('the database name is not echoed', !str_contains($gone['body'], $absentDb));

echo PHP_EOL, '=== health_test: ', $failures === 0
    ? "ALL {$total} CHECKS PASSED ==="
    : "{$failures} of {$total} CHECKS FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
