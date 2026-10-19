<?php
// ============================================================================
// Integration + unit test: duplicate column names survive the target driver.
//
// fetch_assoc(), pg_fetch_all() and sqlsrv_fetch_array(ASSOC) name the row keys
// from the raw column list, so "SELECT 1 AS n, 2 AS n" collapsed to a single
// column and an approved column simply vanished from the result. The three
// drivers now fetch numeric rows and name them through Db\ColumnNames, which
// suffixes repeats (_2, _3) and replaces an empty name with col_N.
//
// The unit half pins the naming rule (shared by all three drivers). The
// integration half runs the real MySQL driver, QueryRunner and ResultWriter
// against the throwaway test database, which is also where the documented
// value behaviour is asserted: numbers arrive as strings, NULL stays NULL.
// SQL Server and PostgreSQL cannot be reached from this runner, so their
// dedupe is covered by the shared helper (and by tests\driver_test.php --type
// against a real server).
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';
require __DIR__ . '/../src/Core/Filesystem.php';
require_once __DIR__ . '/../src/Worker/ResultWriter.php';

test_guard();
\Core\Filesystem::prepare();

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label,
        $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$db = db();
$dedup = static fn (array $names): array => \Db\ColumnNames::deduplicate($names);

// --------------------------- naming rule ---------------------------
$check('unique names pass through in order',
    $dedup(['id', 'name', 'created_at']) === ['id', 'name', 'created_at']);
$check('two repeats -> second gets _2', $dedup(['n', 'n']) === ['n', 'n_2']);
$check('three repeats -> _2 then _3', $dedup(['n', 'n', 'n']) === ['n', 'n_2', 'n_3']);
$check('independent names dedupe independently',
    $dedup(['a', 'b', 'a', 'b']) === ['a', 'b', 'a_2', 'b_2']);
$check('empty names become positional col_N', $dedup(['', '']) === ['col_1', 'col_2']);
$check('empty and repeated names coexist', $dedup(['x', '', 'x']) === ['x', 'col_2', 'x_2']);
$check('a literal suffixed alias after a repeat bumps again',
    $dedup(['a', 'a', 'a_2']) === ['a', 'a_2', 'a_2_2']);
$check('a literal suffixed alias before the repeat wins its slot',
    $dedup(['a', 'a_2', 'a']) === ['a', 'a_2', 'a_3']);
$check('names are not case-folded', $dedup(['N', 'n']) === ['N', 'n']);
$check('names are not trimmed or rewritten', $dedup([' a ', 'a']) === [' a ', 'a']);
$check('empty column list stays empty', $dedup([]) === []);
// A deliberately nasty set: repeats, an empty name, and literal _2 aliases.
$weird = $dedup(['a', 'a', '', 'a_2', 'col_2', 'col_2']);
$check('result is a positional list', $weird === array_values($weird));
$check('every key in the result is unique',
    count(array_unique($weird)) === count($weird), implode(',', $weird));
$check('column count never changes', count($weird) === 6);

// --------------------------- MySQL driver ---------------------------
$params = [
    'db_type' => 'mysql',
    'host' => (string)\Core\Config::get('metadata_db.host', '127.0.0.1'),
    'port' => (int)\Core\Config::get('metadata_db.port', 3306),
    'db' => (string)\Core\Config::get('metadata_db.database', ''),
    'user' => (string)\Core\Config::get('metadata_db.user', ''),
    'password' => (string)\Core\Config::get('metadata_db.password', ''),
    'timeout' => 15,
];
$run = substr(\Core\Str::hex(4), 0, 8);
$probe = 'gw_col_probe_' . $run;
$fileRel = 'results/gw_col_' . $run . '.json';
$connId = 0;
register_shutdown_function(static function () use ($db, $probe, $fileRel, &$connId): void {
    $db->execute("DROP TABLE IF EXISTS {$probe}");
    \Worker\Cleanup::safeUnlink($fileRel);
    if ($connId > 0) {
        $db->execute('DELETE FROM connections WHERE id = ?', [$connId]);
    }
});

$driver = \Db\ConnectionFactory::fromParams($params);
try {
    $stmt = $driver->query("SELECT 1 AS n, 2.5 AS f, 'abc' AS s, NULL AS z, 3 AS n");
    $row = $driver->fetchRow($stmt);
    $check('all five columns survive (repeat became n_2)',
        is_array($row) && array_keys($row) === ['n', 'f', 's', 'z', 'n_2'],
        is_array($row) ? implode(',', array_keys($row)) : 'no row');
    // Documented behaviour: the target drivers hand numbers over as strings.
    $check('values keep their position and NULL survives',
        is_array($row) && array_values($row) === ['1', '2.5', 'abc', null, '3'],
        is_array($row) ? json_encode(array_values($row)) : 'no row');
    $check('numbers arrive as strings, not ints/floats',
        is_array($row) && is_string($row['n']) && is_string($row['f']));
    $driver->free($stmt);

    $db->execute("CREATE TABLE {$probe} (id INT NOT NULL, note VARCHAR(32) NOT NULL)");
    $db->execute("INSERT INTO {$probe} (id, note) VALUES (7, 'seven'), (8, 'eight')");

    $stmt = $driver->query("SELECT id AS c, note AS c FROM {$probe}");
    $row = $driver->fetchRow($stmt);
    $check('duplicate names over real columns keep both values',
        is_array($row) && array_keys($row) === ['c', 'c_2']
        && (string)($row['c'] ?? '') === '7' && ($row['c_2'] ?? '') === 'seven',
        is_array($row) ? json_encode($row) : 'no row');
    $driver->free($stmt);

    $stmt = $driver->query("SELECT id, note FROM {$probe}");
    $row = $driver->fetchRow($stmt);
    $check('non-duplicate results keep their exact keys',
        is_array($row) && array_keys($row) === ['id', 'note']);
    $driver->free($stmt);

    // Column names come from the first SELECT of a UNION; the driver must not
    // invent per-row keys.
    $stmt = $driver->query('SELECT 1 AS n UNION ALL SELECT 2 AS n');
    $a = $driver->fetchRow($stmt);
    $b = $driver->fetchRow($stmt);
    $c = $driver->fetchRow($stmt);
    $check('UNION keeps one column and streams both rows',
        is_array($a) && is_array($b) && $c === null
        && array_keys($a) === ['n'] && array_keys($b) === ['n']
        && (string)$a['n'] === '1' && (string)$b['n'] === '2');
    $driver->free($stmt);

    // Pool reuse: a driver is handed back for a different statement shape, so
    // keys must follow the newest result, never the previous one.
    $stmt = $driver->query('SELECT 1 AS one, 2 AS two');
    $driver->free($stmt);
    $stmt = $driver->query("SELECT id, note, 'x' AS extra FROM {$probe} WHERE id = 7");
    $row = $driver->fetchRow($stmt);
    $check('keys are rebuilt for the next statement on the same connection',
        is_array($row) && array_keys($row) === ['id', 'note', 'extra']);
    $driver->free($stmt);

    // Mid-stream release must not leak rows into the next query either.
    $stmt = $driver->query("SELECT id FROM {$probe}");
    $driver->fetchRow($stmt);
    $driver->free($stmt);
    $stmt = $driver->query('SELECT 1 AS n, 2 AS n');
    $row = $driver->fetchRow($stmt);
    $check('a mid-stream free leaves the next result correctly named',
        is_array($row) && array_keys($row) === ['n', 'n_2']);
    $driver->free($stmt);

    // Introspection statements still work (they never had duplicate names).
    $stmt = $driver->query('SHOW TABLES');
    $row = $driver->fetchRow($stmt);
    $check('SHOW still returns a named row',
        is_array($row) && count($row) === 1 && array_keys($row)[0] !== '0');
    $driver->free($stmt);
    $stmt = $driver->query('EXPLAIN SELECT 1');
    $row = $driver->fetchRow($stmt);
    $check('EXPLAIN keys are unchanged',
        is_array($row) && isset($row['select_type'], $row['Extra']));
    $driver->free($stmt);
} finally {
    $driver->close();
}

// --------------------------- full pipeline ---------------------------
$connName = "gwcol {$run} meta";
$db->execute(
    'INSERT INTO connections (name, db_type, host, port, db_name, username, password_encrypted,
        max_concurrency, is_active, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 2, 1, ?)',
    [$connName, 'mysql', $params['host'], $params['port'] ?: null, $params['db'], $params['user'],
        \Services\Encryption::encrypt($params['password']), now_str()]
);
$connId = $db->insertId();
$connRow = $db->one('SELECT * FROM connections WHERE id = ?', [$connId]);

$captured = [];
$out = \Services\QueryRunner::run(
    (array)$connRow,
    "SELECT 1 AS n, 2 AS n, 'x' AS s",
    15,
    10,
    static function (array $row) use (&$captured): void {
        $captured[] = $row;
    }
);
$check('QueryRunner reports the query ok', ($out['ok'] ?? false) === true, (string)($out['error'] ?? ''));
$check('QueryRunner streams the deduplicated row to the caller',
    $captured !== [] && array_keys($captured[0]) === ['n', 'n_2', 's'],
    $captured === [] ? 'no row' : implode(',', array_keys($captured[0])));

$writer = new \Worker\ResultWriter();
$writer->open(\Core\Filesystem::absoluteFromRelative($fileRel));
$writer->writeRow($captured[0] ?? ['n' => '1', 'n_2' => '2', 's' => 'x']);
$writer->finish(['row_count' => 1]);
$written = json_decode((string)file_get_contents(\Core\Filesystem::absoluteFromRelative($fileRel)), true);
$check('the result file carries every column',
    is_array($written) && ($written['data'][0]['n_2'] ?? null) === '2'
    && ($written['data'][0]['n'] ?? null) === '1',
    json_encode($written['data'][0] ?? null));
\Worker\Cleanup::safeUnlink($fileRel);

echo $failures === 0 ? '=== COLUMN NAMES TEST PASS ===' : "=== COLUMN NAMES TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
