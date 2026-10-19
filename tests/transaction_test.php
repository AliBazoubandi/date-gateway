<?php
// tests/transaction_test.php — Core\Db::transaction() and the two multi-step
// writes that now depend on it:
//   Api\Submit, cache rule 3   : INSERT executions + INSERT calls
//   Worker::finalizeSuccess   : UPDATE executions -> ready + webhook deliveries
//
// The faults are injected in the DATABASE (a BEFORE INSERT trigger that SIGNALs
// for this run's fixture rows only), so the real code paths meet a real
// second-statement failure and what is asserted is what survived: no execution
// scheduled for a call that was never created, no ready row whose clients were
// never told. A healthy pair in the same run is the control.

use Core\Filesystem;
use Core\Str;
use Services\Encryption;
use Worker\Cleanup;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

// ---- child mode: one real delayed submit ----------------------------------
// Submit::handle() answers with json_out(), which exits, so it runs in a child.
// Bearer auth is read from $_SERVER, which the CLI can set directly.
if (($argv[1] ?? '') === '--submit') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$argv[3];
    \Api\Submit::handle((string)$argv[2]);
    exit(0);
}

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$db = db();
Filesystem::prepare();

// Trigger DDL cannot travel through the prepared-statement path Core\Db::execute
// uses (MySQL refuses CREATE/DROP TRIGGER there), so the injection helper talks
// to the connection directly. Only fixed, test-built strings go through here.
$raw = static function (string $sql) use ($db): void {
    if ($db->conn()->query($sql) === false) {
        throw new \RuntimeException('raw SQL refused: ' . $db->conn()->error . ' -- ' . $sql);
    }
};

// ---------------- per-run fixtures, self-cleaning ----------------------------
$run = substr(Str::hex(4), 0, 8);
$now = now_str();
$connectionIds = [];
$requestIds = [];
$clientIds = [];
$triggers = [];

$wipe = static function () use ($db, $raw, &$connectionIds, &$requestIds, &$clientIds, &$triggers): void {
    foreach ($triggers as $name) {
        try {
            $raw('DROP TRIGGER IF EXISTS `' . $name . '`');
        } catch (\Throwable) {
            // the schema is dropped by the runner anyway
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
        $db->execute("DELETE FROM clients WHERE id IN ($c)");
    }
    if ($connectionIds !== []) {
        $db->execute('DELETE FROM connections WHERE id IN (' . implode(',', array_map('intval', $connectionIds)) . ')');
    }
};
register_shutdown_function($wipe);

$mkConn = static function (string $name) use ($db, $now): int {
    $db->execute(
        'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted,
                max_concurrency, is_active, created_at)
         VALUES (?, ?, ?, NULL, ?, ?, ?, 2, 1, ?)',
        [$name, 'mysql', '127.0.0.1', 'mysql', 'root', Encryption::encrypt(''), $now]
    );
    return $db->insertId();
};
$connId = $mkConn("tx {$run} target");
$connectionIds[] = $connId;

$mkRequest = static function (string $code, string $sql) use ($db, $now, $connId): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
                delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, 15, 10, 1000, 1, ?)',
        [$code, $code, $sql, $connId, 'delayed', $now]
    );
    return $db->insertId();
};
$reqSubmit = $mkRequest("tx{$run}.submit", 'SELECT 1 AS ping');
$reqBlocked = $mkRequest("tx{$run}.blocked", 'SELECT 1 AS ping');
$reqHealthy = $mkRequest("tx{$run}.healthy", 'SELECT 1 AS ping');
array_push($requestIds, $reqSubmit, $reqBlocked, $reqHealthy);

$mkClient = static function (string $name) use ($db, $now): array {
    $key = Str::hex(24); // 48 chars: the API accepts 40..128
    $db->execute(
        'INSERT INTO clients (name, type, api_key_hash, is_active, created_at) VALUES (?, ?, ?, 1, ?)',
        [$name, 'agent', hash('sha256', $key), $now]
    );
    return [(int)$db->insertId(), $key];
};
[$clientSubmit, $keySubmit] = $mkClient("tx {$run} submitter");
[$clientWorker, $keyWorker] = $mkClient("tx {$run} waiter");
array_push($clientIds, $clientSubmit, $clientWorker);

$grant = static function (int $clientId, int $requestId) use ($db, $now): void {
    $db->execute(
        'INSERT INTO client_request_permissions (client_id, request_id, granted_at) VALUES (?, ?, ?)',
        [$clientId, $requestId, $now]
    );
};
$grant($clientSubmit, $reqSubmit);
$grant($clientWorker, $reqBlocked);
$grant($clientWorker, $reqHealthy);

$mkExec = static function (int $requestId) use ($db, $now): int {
    $db->execute(
        "INSERT INTO executions (request_id, mode, status, scheduled_at) VALUES (?, 'delayed', 'scheduled', ?)",
        [$requestId, $now]
    );
    return $db->insertId();
};
$mkCall = static function (string $callId, int $clientId, int $requestId, int $execId) use ($db, $now): void {
    $db->execute(
        'INSERT INTO calls (id, client_id, request_id, execution_id, created_at, ip, was_shared)
         VALUES (?, ?, ?, ?, ?, ?, 0)',
        [$callId, $clientId, $requestId, $execId, $now, 'cli-test']
    );
};
$execBlocked = $mkExec($reqBlocked);
$execHealthy = $mkExec($reqHealthy);
$callBlocked = Str::hex(16);
$callHealthy = Str::hex(16);
$mkCall($callBlocked, $clientWorker, $reqBlocked, $execBlocked);
$mkCall($callHealthy, $clientWorker, $reqHealthy, $execHealthy);

// Runs a child process: returns [exit code, stdout, stderr].
$child = static function (array $args): array {
    $proc = proc_open(array_merge([PHP_BINARY], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        return [-1, '', 'child did not start'];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};

$block = static function (string $name, string $body) use ($raw, &$triggers): void {
    $triggers[] = $name;
    $raw('DROP TRIGGER IF EXISTS `' . $name . '`');
    $raw('CREATE TRIGGER `' . $name . '` BEFORE INSERT ON ' . $body);
};
$unblock = static function (string $name) use ($raw, &$triggers): void {
    $raw('DROP TRIGGER IF EXISTS `' . $name . '`');
    $triggers = array_values(array_filter($triggers, static function (string $t) use ($name): bool {
        return $t !== $name;
    }));
};

// ============================ 1) engines =====================================
// Without InnoDB the word "rollback" means nothing: every assertion below would
// pass against a MyISAM table while the half-written rows stayed on disk.
foreach (['executions', 'calls', 'webhook_deliveries', 'requests'] as $table) {
    $engine = strtoupper((string)$db->value(
        'SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    ));
    $check("{$table} is InnoDB (rollback can actually apply)", $engine === 'INNODB', $engine);
}

// ========================= 2) the primitive ==================================
$countRequests = static fn (): int => (int)$db->value('SELECT COUNT(*) FROM requests');

$thrown = null;
try {
    $db->transaction(static function () use ($db, $now, $connId): void {
        $db->execute(
            'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
                    delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, 15, 10, 1000, 1, ?)',
            ['tx-rollback-probe', 'tx-rollback-probe', 'SELECT 1', $connId, 'delayed', $now]
        );
        throw new \RuntimeException('injected rollback');
    });
} catch (\Throwable $e) {
    $thrown = $e;
}
$check('transaction() rethrows the original error',
    $thrown !== null && $thrown->getMessage() === 'injected rollback',
    $thrown !== null ? get_class($thrown) . ': ' . $thrown->getMessage() : 'nothing thrown');
$check('a throwing body leaves nothing behind',
    (int)$db->value("SELECT COUNT(*) FROM requests WHERE code = 'tx-rollback-probe'") === 0);

$id = $db->transaction(static function () use ($db, $now, $connId): int {
    $db->execute(
        'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
                delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, 15, 10, 1000, 1, ?)',
        ['tx-commit-probe', 'tx-commit-probe', 'SELECT 1', $connId, 'delayed', $now]
    );
    return $db->insertId();
});
$check('a returning body is committed',
    $id > 0 && (int)$db->value("SELECT COUNT(*) FROM requests WHERE code = 'tx-commit-probe'") === 1);
$db->execute("DELETE FROM requests WHERE code = 'tx-commit-probe'");

$afterPlain = $db->execute(
    'INSERT INTO requests (code, name, sql_text, connection_id, response_mode, instant_cache_seconds,
            delay_minutes, ttl_minutes, timeout_seconds, max_rows, is_active, created_at)
     VALUES (?, ?, ?, ?, ?, 0, 0, 15, 10, 1000, 1, ?)',
    ['tx-after-probe', 'tx-after-probe', 'SELECT 1', $connId, 'delayed', $now]
);
$check('autocommit is back after the rollback (one statement, no explicit commit)',
    $afterPlain === 1
    && (int)$db->value("SELECT COUNT(*) FROM requests WHERE code = 'tx-after-probe'") === 1);
$db->execute("DELETE FROM requests WHERE code = 'tx-after-probe'");
$check('the request count is exactly what the fixtures made',
    $countRequests() === 3, (string)$countRequests());

// ================== 3) Submit cache rule 3, real endpoint ====================
$block('tx_block_call',
    "calls FOR EACH ROW BEGIN
       IF NEW.request_id = {$reqSubmit} THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected: call insert refused';
       END IF;
     END");
[$code, $out, $err] = $child(['tests/transaction_test.php', '--submit', "tx{$run}.submit", $keySubmit]);
$check('submit child refused (non-zero exit)', $code !== 0, 'exit ' . $code . ' ' . trim($out . ' ' . $err));
$check('the refusal is the injected one', str_contains($out . $err, 'injected'),
    Str::truncate(trim($out . $err), 200));
$check('no orphan execution survived the rolled-back submit',
    (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqSubmit]) === 0);
$check('no call row either',
    (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqSubmit]) === 0);
$unblock('tx_block_call');

[$code, $out, $err] = $child(['tests/transaction_test.php', '--submit', "tx{$run}.submit", $keySubmit]);
$said = json_decode(trim($out), true);
$check('control: the same submit succeeds once the refusal is gone',
    $code === 0 && is_array($said) && ($said['status'] ?? '') === 'scheduled',
    'exit ' . $code . ' ' . trim(substr($out . $err, 0, 200)));
$check('control: execution and call were both written',
    (int)$db->value('SELECT COUNT(*) FROM executions WHERE request_id = ?', [$reqSubmit]) === 1
    && (int)$db->value('SELECT COUNT(*) FROM calls WHERE request_id = ?', [$reqSubmit]) === 1);

// ================ 4) worker finalizeSuccess, real pass ======================
$block('tx_block_delivery',
    "webhook_deliveries FOR EACH ROW BEGIN
       IF NEW.call_id = '{$callBlocked}' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected: delivery insert refused';
       END IF;
     END");
$beforeFiles = Filesystem::absoluteFromRelative("results/{$execBlocked}.json");
[$code, $out, $err] = $child(['cli/worker.php']);
$check('worker pass completed (exit 0), the poisoned run did not stall it',
    $code === 0, 'exit ' . $code . ' ' . trim(substr($out . $err, -300)));
$blockedRow = $db->one('SELECT status, error_message, result_path FROM executions WHERE id = ?', [$execBlocked]);
$healthyRow = $db->one('SELECT status, result_path FROM executions WHERE id = ?', [$execHealthy]);
$check('the blocked execution is NOT left ready',
    (string)$blockedRow['status'] !== 'ready', (string)$blockedRow['status']);
$check('and it is recorded as failed with the reason',
    (string)$blockedRow['status'] === 'failed'
    && str_contains((string)$blockedRow['error_message'], 'result could not be recorded'),
    (string)$blockedRow['status'] . ' / ' . Str::truncate((string)$blockedRow['error_message'], 120));
$check('no half-applied ready state: no result path on the failed row',
    $blockedRow['result_path'] === null, var_export($blockedRow['result_path'], true));
$check('the published result file was removed with the rolled-back row',
    !is_file($beforeFiles));
$check('no delivery row for the blocked call',
    (int)$db->value('SELECT COUNT(*) FROM webhook_deliveries WHERE call_id = ?', [$callBlocked]) === 0);
$check('the other execution of the same pass became ready normally',
    (string)$healthyRow['status'] === 'ready', (string)$healthyRow['status']);
$check('and its clients were told (one delivery row)',
    (int)$db->value('SELECT COUNT(*) FROM webhook_deliveries WHERE call_id = ?', [$callHealthy]) === 1);
$check('its result file exists',
    is_file(Filesystem::absoluteFromRelative((string)$healthyRow['result_path'])));
Cleanup::safeUnlink("results/{$execHealthy}.json");
$unblock('tx_block_delivery');

echo $failures === 0 ? '=== TRANSACTION TEST PASS ===' : "=== TRANSACTION TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
