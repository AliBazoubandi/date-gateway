<?php
// ============================================================================
// Driver smoke test (no metadata DB required).
//
// Usage:
//   php tests\driver_test.php --type=sqlserver --host=HOST [--port=1433]
//        --db=DB --user=U --password=P [--timeout=30] [--sql="SELECT ..."]
//   php tests\driver_test.php --preset=NAME   (name from config: test_connections)
//
// Runs the SQL, streams up to 5 rows, reports timings, error / timeout class.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';
require __DIR__ . '/../src/Core/Filesystem.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/i', $a, $m)) {
        $args[strtolower($m[1])] = $m[2];
    }
}

// Preset from config.test_connections when given.
if (isset($args['preset'])) {
    $presets = (array)Core\Config::get('test_connections', []);
    if (!isset($presets[$args['preset']])) {
        fwrite(STDERR, "No preset '{$args['preset']}' in config under 'test_connections'.\n");
        exit(2);
    }
    $args = array_merge($args, array_change_key_case((array)$presets[$args['preset']], CASE_LOWER));
}

$type = strtolower((string)($args['type'] ?? ''));
if (!in_array($type, ['sqlserver', 'pgsql', 'mysql'], true)) {
    fwrite(STDERR, "--type must be sqlserver|pgsql|mysql\n");
    exit(2);
}

$defaults = [
    'sqlserver' => 'SELECT 1 AS ping',
    'pgsql' => 'SELECT 1 AS ping',
    'mysql' => 'SELECT 1 AS ping',
];

$params = [
    'db_type' => $type,
    'host' => (string)($args['host'] ?? $defaults[$type]),
    'port' => isset($args['port']) && $args['port'] !== '' ? (int)$args['port'] : null,
    'db' => (string)($args['db'] ?? ''),
    'user' => (string)($args['user'] ?? ''),
    'password' => (string)($args['password'] ?? ''),
    'timeout' => (int)($args['timeout'] ?? 30),
];

$sql = (string)($args['sql'] ?? '') !== '' ? (string)$args['sql'] : $defaults[$type];

echo "Testing {$type} on {$params['host']} {$params['db']} (timeout {$params['timeout']}s)\n";
echo "SQL: {$sql}\n\n";

$t0 = microtime(true);
try {
    $driver = Db\ConnectionFactory::fromParams($params);
    $driver->setQueryTimeout($params['timeout']);
    echo '[ok] connected in ', round((microtime(true) - $t0) * 1000), " ms\n";
} catch (Throwable $e) {
    echo '[connect FAILED] ', $e->getMessage(), "\n";
    exit(1);
}

$rowCount = 0;
$tq = microtime(true);
try {
    $stmt = $driver->query($sql);
    while ($rowCount < 5 && ($row = $driver->fetchRow($stmt)) !== null) {
        $rowCount++;
        echo 'row ', $rowCount, ': ', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }
    // keep reading to prove streaming works to the end
    while ($driver->fetchRow($stmt) !== null) {
        $rowCount++;
    }
} catch (Db\DbQueryException $e) {
    echo '[query]', $e->isTimeout() ? ' TIMEOUT' : ' FAILED', ': ', $e->getMessage(), "\n";
    echo 'timedOut(): ', $driver->timedOut() ? 'true' : 'false', "\n";
    $driver->close();
    exit(1);
} catch (Throwable $e) {
    echo '[query FAILED] ', get_class($e), ': ', $e->getMessage(), "\n";
    $driver->close();
    exit(1);
}

$driver->free($stmt);
echo "\n[ok] streamed ", $rowCount, ' row(s) in ', round((microtime(true) - $tq) * 1000), " ms, error='", $driver->lastError(), "'\n";
$driver->close();
exit(0);
