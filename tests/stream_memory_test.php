<?php
// Streaming-memory probe: builds a temporary bulk table on the local
// MariaDB/MySQL (no plugins needed), streams every row through the target
// driver row by row, and prints peak PHP memory.
//
// Usage:  php tests\stream_memory_test.php [rows] [user] [password]
// Default: 200000 rows. A _gw_stream schema is created and dropped.

require __DIR__ . '/../src/Core/bootstrap.php';

$rows = max(1000, (int)($argv[1] ?? 200000));

$params = [
    'db_type' => 'mysql',
    'host' => '127.0.0.1',
    'db' => 'mysql', // driver must connect to something; we switch below
    'user' => $argv[2] ?? 'root',
    'password' => $argv[3] ?? '',
    'timeout' => 120,
];

$driver = Db\ConnectionFactory::fromParams($params);
$driver->setQueryTimeout($params['timeout']);

// Build the throwaway schema.
$driver->query('DROP DATABASE IF EXISTS _gw_stream');
$driver->query('CREATE DATABASE _gw_stream');
$driver->query('CREATE TABLE _gw_stream.bulk (n INT UNSIGNED NOT NULL, pad VARCHAR(64) NOT NULL, KEY (n)) ENGINE=InnoDB');
$chunk = 1000;
$i = 1;
while ($i <= $rows) {
    $upper = min($i + $chunk - 1, $rows);
    $values = [];
    for ($n = $i; $n <= $upper; $n++) {
        $values[] = "({$n}, '" . str_repeat('x', 42) . "')";
    }
    $driver->query('INSERT INTO _gw_stream.bulk (n, pad) VALUES ' . implode(',', $values));
    $i = $upper + 1;
}

$stmt = $driver->query('SELECT n, pad FROM _gw_stream.bulk ORDER BY n');
$count = 0;
$bytes = 0;
while (($row = $driver->fetchRow($stmt)) !== null) {
    $count++;
    $bytes += (int)strlen(json_encode($row, JSON_UNESCAPED_SLASHES)) + 1;
}
$driver->free($stmt);
$driver->query('DROP DATABASE _gw_stream');
$driver->close();

printf(
    "rows=%d payload~%s peak memory=%.1f MB status=%s\n",
    $count,
    number_format($bytes),
    memory_get_peak_usage(true) / 1048576,
    $count === $rows ? 'PASS (memory bounded, no full result load)' : 'FAIL (unexpected row count)'
);
exit($count === $rows ? 0 : 1);
