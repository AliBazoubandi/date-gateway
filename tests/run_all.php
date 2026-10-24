<?php
// ============================================================================
// Data Gateway - full test runner (portable: Windows, Linux, macOS).
//
// This is what tests\run_all.ps1 calls; CI runs it directly. It builds a
// THROWAWAY metadata database (data_gateway_test_<random>) and a throwaway
// storage directory in the system temp dir (gw-test-<random>), points the
// gateway at them with GATEWAY_DB_NAME / GATEWAY_STORAGE_DIR, runs every suite
// against them, and drops both afterwards - including on failure, through a
// shutdown handler registered BEFORE either is created.
//
// The live data_gateway database and the real storage/ are never touched:
// every suite calls test_guard() and refuses to run unless GATEWAY_TEST_MODE=1
// and the configured database name contains "_test".
//
// Usage:
//   php tests/run_all.php [--mysql=PATH] [--php=PATH] [--root-user=NAME]
//        [--root-host=HOST] [--root-port=PORT] [--only=name,name]
//        [--rows=N] [--target-user=U] [--target-password=P] [--keep] [--list]
//        [--drop-stale [--stale-minutes=N] [--force]]
//
//   --mysql        mysql client binary (default: GATEWAY_TEST_MYSQL_EXE, then
//                  PATH, then the usual XAMPP/MySQL locations)
//   --php          php binary every suite runs with (default: this one)
//   --root-*       admin credentials used to create/drop the throwaway schema
//   --only         run just these suites (see --list)
//   --rows         row count for stream_memory_test (default 100000)
//   --target-*     credentials stream_memory_test uses for its bulk table
//                  (default: the test binary's own, i.e. root with no password)
//   --keep         leave the test database and storage dir behind (debugging)
//   --drop-stale   drop leftover data_gateway_test_* schemas older than
//                  --stale-minutes (default 60) and exit. Refuses without a
//                  confirmation on a TTY, or --force in a script. A SIGKILL
//                  cannot be caught, so this is the cleanup for such a run;
//                  the age floor keeps it away from a run that is still active.
// ============================================================================

declare(strict_types=1);

$projectRoot = str_replace('\\', '/', dirname(__DIR__));

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[strtolower($m[1])] = $m[2] ?? '1';
    } else {
        fwrite(STDERR, "Unrecognized argument: {$arg}\n");
        exit(2);
    }
}
if (isset($opts['help'])) {
    echo "See the header comment of tests/run_all.php for the options.\n";
    exit(0);
}

/** Locate the mysql client: explicit option, env var, PATH, known defaults. */
$findMysql = static function (?string $explicit): string {
    if ($explicit !== null && $explicit !== '') {
        return $explicit;
    }
    $fromEnv = getenv('GATEWAY_TEST_MYSQL_EXE');
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }
    $seps = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
    foreach (array_filter(explode($seps, (string)(getenv('PATH') ?: ''))) as $dir) {
        foreach (['mysql', 'mysql.exe'] as $candidate) {
            $full = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($full)) {
                return $full;
            }
        }
    }
    foreach (['C:/xampp/mysql/bin/mysql.exe',
              'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe'] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    fwrite(STDERR, "Cannot find a mysql client. Pass --mysql=PATH.\n");
    exit(2);
};

$phpExe = (string)(($opts['php'] ?? '') !== '' ? $opts['php']
    : (getenv('GATEWAY_TEST_PHP_EXE') ?: PHP_BINARY));
$mysqlExe = $findMysql(isset($opts['mysql']) ? (string)$opts['mysql'] : null);
$rootUser = (string)($opts['root-user'] ?? 'root');
$rootHost = isset($opts['root-host']) ? (string)$opts['root-host'] : null;
$rootPort = isset($opts['root-port']) ? (string)$opts['root-port'] : null;
$only = isset($opts['only'])
    ? array_values(array_filter(array_map('trim', explode(',', (string)$opts['only']))))
    : null;

// ---- the suites, in order --------------------------------------------------
// [run_all name, what it covers, extra argv]
$suites = [
    ['lint', 'php -l over every file', []],
    ['smoke_db', 'schema + binder', []],
    ['encryption_test', 'master key, round trip, stored blob', ['--db']],
    ['links_test', 'download / webhook link building', []],
    ['filesystem_test', 'stored path validation', []],
    ['sqlguard_test', 'read-only SQL scanner', []],
    ['parameters_test', 'named query parameter parser and binder', []],
    ['readonly_driver_test', 'MySQL read-only transaction', []],
    ['pool_test', 'per-connection worker pool', []],
    ['resultwriter_test', 'streamed result files + byte caps', []],
    ['throttle_test', 'atomic per-client submit throttle', []],
    ['proxy_test', 'trusted proxies + session defaults', []],
    ['driver_tls_test', 'SQL Server connection options', []],
    ['str_json_test', 'Str::json never returns false', []],
    ['sapi_bootstrap_test', 'startup failure under both SAPIs', []],
    ['transaction_test', 'submit + finalize atomicity', []],
    ['column_names_test', 'duplicate column naming', []],
    ['instant_ttl_test', 'instant cache window clamp', []],
    ['instant_file_leak_test', 'failed instant file leaves no .tmp', []],
    ['health_test', 'GET /health checks + 200/503 verdict', []],
    ['api_smoke', 'end-to-end API over php -S', []],
    ['admin_smoke', 'end-to-end admin panel over php -S', []],
    ['worker_smoke', 'real worker pass', []],
    ['retention_test', 'retention pruning + free-space floor', []],
    ['webhook_smoke', 'real webhook delivery + signature', []],
    ['webhook_url_test', 'webhook SSRF policy', []],
    ['stream_memory_test', 'streaming memory probe', []],
];
if (isset($opts['list'])) {
    foreach ($suites as [$name, $what]) {
        echo str_pad($name, 22), $what, "\n";
    }
    exit(0);
}

/**
 * Start a command, wait for it, then print what it wrote.
 *
 * The output goes through a temporary file instead of a pipe held by this
 * process: a suite leaves children alive (php -S, a pool worker) that inherit
 * the write handle, so waiting for EOF would hang on a finished suite, and
 * letting children write to this process's own handle scrambles the log (their
 * writes and the runner's interleave at the same file offset).
 */
function run_command(array $cmd, ?string $stdinFile = null): int
{
    $outFile = (string)tempnam(sys_get_temp_dir(), 'gw_suite_out_');
    $pipes = [];
    $proc = proc_open($cmd, [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
        1 => ['file', $outFile, 'w'],
        2 => ['file', $outFile, 'a'],
    ], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, 'Cannot start: ' . implode(' ', $cmd) . "\n");
        @unlink($outFile);
        return 127;
    }
    if (is_resource($pipes[0] ?? null)) {
        fclose($pipes[0]);
    }
    $code = proc_close($proc);
    $text = (string)file_get_contents($outFile);
    @unlink($outFile);
    if ($text !== '') {
        echo $text;
    }
    return $code;
}

/** The mysql client's own argument prefix for the schema admin. */
function mysqlBaseArgs(): array
{
    global $mysqlExe, $rootUser, $rootHost, $rootPort;
    $cmd = [$mysqlExe, '-u', $rootUser];
    if ($rootHost !== null) {
        $cmd[] = '--host=' . $rootHost;
    }
    if ($rootPort !== null) {
        $cmd[] = '--port=' . $rootPort;
    }
    return $cmd;
}

/** One mysql client statement as the schema admin. */
function mysqlAdmin(string $sql): int
{
    return run_command(array_merge(mysqlBaseArgs(), ['-e', $sql]));
}

/**
 * One mysql query whose rows this process needs back (tab-separated, no
 * headers). Only fixed, test-built SQL goes through it; it exists so the
 * throwaway-schema housekeeping can be done without a PHP DB dependency.
 *
 * @return list<string> one entry per row; [] on an error (stderr is printed)
 */
function mysqlRows(string $sql): array
{
    $outFile = (string)tempnam(sys_get_temp_dir(), 'gw_sql_');
    $pipes = [];
    $proc = proc_open(array_merge(mysqlBaseArgs(), ['-N', '-B', '-e', $sql]),
        [1 => ['file', $outFile, 'w'], 2 => ['file', $outFile, 'a']], $pipes);
    if (!is_resource($proc)) {
        @unlink($outFile);
        return [];
    }
    $code = proc_close($proc);
    $text = (string)file_get_contents($outFile);
    @unlink($outFile);
    if ($code !== 0) {
        fwrite(STDERR, $text);
        return [];
    }
    return array_values(array_filter(explode("\n", $text), static fn (string $l): bool => $l !== ''));
}

function removeTree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . '/' . $entry;
        if (is_dir($full) && !is_link($full)) {
            removeTree($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($dir);
}

function lint_all(string $phpExe, string $root): int
{
    $bad = 0;
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f): bool =>
            !preg_match('#/(vendor|\.git|storage|node_modules)/#', str_replace('\\', '/', $f->getPathname()))
    ));
    foreach ($files as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg($phpExe) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $bad = 1;
            echo implode("\n", $out), "\n";
        }
    }
    return $bad;
}

/** @return int|string suite exit code */
function run_suite(string $name, array $tail, string $phpExe, string $root, array $opts)
{
    if ($name === 'lint') {
        return lint_all($phpExe, $root);
    }
    if ($name === 'stream_memory_test') {
        $tail = [(string)max(1000, (int)($opts['rows'] ?? 100000))];
        if (isset($opts['target-user'])) {
            $tail[] = (string)$opts['target-user'];
            $tail[] = (string)($opts['target-password'] ?? '');
        }
    }
    $file = $root . '/tests/' . $name . '.php';
    if (!is_file($file)) {
        fwrite(STDERR, "Missing suite file: {$file}\n");
        return 2;
    }
    return run_command(array_merge([$phpExe, $file], $tail));
}

// ---- throwaway environment -------------------------------------------------
$suffix = bin2hex(random_bytes(4));
$testDb = 'data_gateway_test_' . $suffix;
// NOT under the project's storage/: a suite that dies must not leave artifacts
// inside the volume the live gateway serves results from. The system temp dir
// is throwaway by construction and is writable by the CLI user.
$storageDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'gw-test-' . $suffix;

// The account the gateway itself connects with: name only, never the password,
// read from config/.env (default gateway).
$dbUser = 'gateway';
$envFile = $projectRoot . '/config/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*GATEWAY_DB_USER\s*=\s*(\S+)\s*$/', $line, $m)) {
            $dbUser = $m[1];
            break;
        }
    }
}

// ---- --drop-stale: the cleanup for a run that was killed --------------------
// A SIGKILL cannot be caught, so its schema survives the shutdown handler. Only
// names with the documented prefix and random suffix qualify, and only when
// they are older than --stale-minutes, so a run that is still active (its
// schema was just imported) is never touched.
if (isset($opts['drop-stale'])) {
    $staleMinutes = max(1, (int)($opts['stale-minutes'] ?? 60));
    // The age is computed BY MySQL (TIMESTAMPDIFF against its own clock): the
    // schema's CREATE_TIME is in the server's time zone, and strtotime() in
    // this process would read it in PHP's and produce a negative age on a host
    // whose PHP timezone differs from the DB's (as it does here: Tehran vs UTC).
    $rows = mysqlRows(
        "SELECT s.SCHEMA_NAME,
                COALESCE((SELECT TIMESTAMPDIFF(SECOND, MIN(t.CREATE_TIME), NOW())
                          FROM information_schema.TABLES t
                          WHERE t.TABLE_SCHEMA = s.SCHEMA_NAME), 0)
         FROM information_schema.SCHEMATA s
         WHERE s.SCHEMA_NAME LIKE 'data\\_gateway\\_test\\_%'"
    );
    $stale = [];
    $fresh = [];
    foreach ($rows as $row) {
        $parts = explode("\t", $row);
        $name = (string)($parts[0] ?? '');
        // The documented shape only: data_gateway_test_ + a lowercase
        // alphanumeric suffix. Anything else is not ours to drop.
        if (preg_match('/^data_gateway_test_[a-z0-9]{4,32}$/', $name) !== 1) {
            continue;
        }
        $age = (int)($parts[1] ?? 0);
        if ($age >= $staleMinutes * 60) {
            $stale[] = [$name, $age];
        } else {
            $fresh[] = [$name, $age];
        }
    }
    if ($fresh !== []) {
        foreach ($fresh as [$name, $age]) {
            echo "keep  {$name} (created {$age}s ago, younger than --stale-minutes={$staleMinutes})\n";
        }
    }
    if ($stale === []) {
        echo "No stale data_gateway_test_* schema to drop.\n";
        exit(0);
    }
    foreach ($stale as [$name, $age]) {
        echo "stale {$name} (created {$age}s ago)\n";
    }
    if (!isset($opts['force'])) {
        $interactive = function_exists('stream_isatty') && @stream_isatty(STDIN);
        if (!$interactive) {
            fwrite(STDERR, "Refusing to drop " . count($stale) . " schema(s) without --force on a non-interactive input.\n");
            exit(2);
        }
        echo 'Drop ' . count($stale) . ' schema(s)? [y/N] ';
        $answer = strtolower(trim((string)fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            echo "Nothing dropped.\n";
            exit(0);
        }
    }
    $bad = 0;
    foreach ($stale as [$name]) {
        if (mysqlAdmin("DROP DATABASE IF EXISTS `{$name}`;") === 0) {
            echo "dropped {$name}\n";
        } else {
            $bad++;
            fwrite(STDERR, "could not drop {$name}\n");
        }
    }
    exit($bad === 0 ? 0 : 1);
}

echo "== php:          {$phpExe}\n";
echo "== mysql:        {$mysqlExe}\n";
echo "== test DB:      {$testDb}\n";
echo "== test storage: {$storageDir}\n";

// ---- throwaway environment -------------------------------------------------
// The shutdown handler is registered BEFORE the first thing it has to clean up
// is created. Registered after (as it used to be) an import failure, a mkdir
// failure or an exit(2) in between left a data_gateway_test_* schema - and the
// grants that go with it - on a server that may also host the live database.
register_shutdown_function(static function () use ($testDb, $storageDir, $opts): void {
    echo "\n== cleanup ==\n";
    if (isset($opts['keep'])) {
        echo "--keep: left {$testDb} and {$storageDir} behind\n";
        return;
    }
    if (mysqlAdmin("DROP DATABASE IF EXISTS `{$testDb}`;") === 0) {
        echo "dropped {$testDb}\n";
    } else {
        // A leaked throwaway schema is a real failure: it keeps the grants and
        // the fixture rows alive on a server that may also host production.
        echo "WARN: could not drop {$testDb} - drop it manually (or run tests/run_all.php --drop-stale --force)!\n";
    }
    if (is_dir($storageDir)) {
        removeTree($storageDir);
        if (is_dir($storageDir)) {
            echo "WARN: {$storageDir} still exists - remove it manually!\n";
        } else {
            echo "removed {$storageDir}\n";
        }
    }
});

if (mysqlAdmin("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") !== 0) {
    fwrite(STDERR, "Cannot create the test database '{$testDb}' - is the server up, and are --root-user/--root-host/--root-port right?\n");
    exit(2);
}
// Grants on the throwaway schema only; they vanish when it is dropped.
foreach (['localhost', '127.0.0.1'] as $grantHost) {
    if (mysqlAdmin("GRANT ALL PRIVILEGES ON `{$testDb}`.* TO '{$dbUser}'@'{$grantHost}';") !== 0) {
        echo "WARN: could not grant on {$testDb} to '{$dbUser}'@'{$grantHost}' (that account must exist and be able to reach this schema)\n";
    }
}
$schemaFile = $projectRoot . '/sql/schema.sql';
// Import through the client's stdin: schema.sql is a script of many statements,
// not something -e can carry.
$import = run_command(array_merge(
    mysqlBaseArgs(),
    ['--default-character-set=utf8mb4', $testDb]
), $schemaFile);
if ($import !== 0) {
    fwrite(STDERR, "Cannot import sql/schema.sql into '{$testDb}'\n");
    exit(2);
}
if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
    fwrite(STDERR, "Cannot create the test storage directory {$storageDir}\n");
    exit(2);
}

// ---- environment every child inherits --------------------------------------
foreach ([
    'GATEWAY_TEST_MODE' => '1',
    'GATEWAY_DB_NAME' => $testDb,
    'GATEWAY_STORAGE_DIR' => $storageDir,
    // Links must be built from a real base URL; http is accepted only because
    // the suites run with GATEWAY_DEBUG=1 (see Links::base / cli\check.php).
    'GATEWAY_BASE_URL' => 'http://127.0.0.1',
    'GATEWAY_DEBUG' => '1',
    // Tiny inline cap so api_smoke reaches the 413 result_too_large path with a
    // small fixture row; real deployments keep the 16 MiB default.
    'GATEWAY_INSTANT_MAX_BYTES' => '2048',
    // webhook_smoke's receiver listens on 127.0.0.1 over http, which the SSRF
    // policy blocks by default (see Services\WebhookUrl).
    'GATEWAY_WEBHOOK_ALLOW_HTTP' => '1',
    'GATEWAY_WEBHOOK_ALLOW_LOOPBACK' => '1',
] as $k => $v) {
    putenv($k . '=' . $v);
}
// Suites spawn `php` (and `php -S`) from PATH: pin that to this binary.
$seps = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
putenv('PATH=' . rtrim(str_replace('\\', '/', dirname($phpExe)), '/') . $seps . (string)(getenv('PATH') ?: ''));

// ---- run -------------------------------------------------------------------
$failures = 0;
$results = [];

foreach ($suites as [$name, $what, $tail]) {
    if ($only !== null && !in_array($name, $only, true)) {
        continue;
    }
    echo "\n--- {$name} ({$what}) ---\n";
    $code = run_suite($name, $tail, $phpExe, $projectRoot, $opts);
    $results[] = [$name, $code === 0 ? 'PASS' : "FAIL({$code})"];
    echo $code === 0 ? "PASS  {$name}\n" : "FAIL  {$name} (exit {$code})\n";
    if ($code !== 0) {
        $failures++;
    }
}

echo "\n== summary ==\n";
foreach ($results as [$name, $status]) {
    echo str_pad($status, 12), $name, "\n";
}
if ($failures > 0) {
    echo "== RUN_ALL: {$failures} suite(s) FAILED ==\n";
    exit(1);
}
echo "== RUN_ALL: ALL SUITES PASSED ==\n";
exit(0);
