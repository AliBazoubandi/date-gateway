<?php
// Unit test for Db\SqlServerDriver's connection options: the transport
// encryption / certificate-validation defaults, the handshake cap, and the
// config wiring in Db\ConnectionFactory::normalize().
//
// No SQL Server is contacted: the shipped config defaults are read from a child
// that sees no GATEWAY_* variables, and the one real connect attempt aims at a
// closed port on this host.

declare(strict_types=1);

use Core\Config;
use Db\ConnectionFactory;
use Db\SqlServerDriver;

if (($argv[1] ?? '') === '--dump-shipped') {
    require __DIR__ . '/../src/Core/Env.php';
    require __DIR__ . '/../src/Core/IpRange.php';
    $cfg = require __DIR__ . '/../config/config.php';
    echo json_encode($cfg['connections']), PHP_EOL;
    exit(0);
}

require __DIR__ . '/../src/Core/bootstrap.php';

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

$base = ['db' => 'erp', 'user' => 'reader', 'password' => 'secret'];
$opt = static function (array $over) use ($base): array {
    return SqlServerDriver::connectionOptions(array_merge($base, ['timeout' => 30], $over));
};

$check('the session is encrypted unless told otherwise', ($opt([])['Encrypt'] ?? null) === true);
$check('an unvalidated certificate is refused by default',
    ($opt([])['TrustServerCertificate'] ?? null) === false);
$check('encrypt=off is honored when a server cannot do TLS',
    ($opt(['encrypt' => false])['Encrypt'] ?? null) === false);
$check('trust=on only when the caller says so',
    ($opt(['trust_server_certificate' => true])['TrustServerCertificate'] ?? null) === true);

$check('a 300 s query timeout does not become a 300 s handshake',
    ($opt(['timeout' => 300])['LoginTimeout'] ?? null) === 15,
    'got ' . json_encode($opt(['timeout' => 300])['LoginTimeout'] ?? null));
$check('a 3 s query timeout is not stretched to the cap',
    ($opt(['timeout' => 3])['LoginTimeout'] ?? null) === 5);
$check('a normal timeout uses the query value',
    ($opt(['timeout' => 10])['LoginTimeout'] ?? null) === 10);
$check('a missing timeout falls back to the normal default',
    ($opt(['timeout' => null])['LoginTimeout'] ?? null) === 15);
$check('the cap itself is a knob', ($opt(['timeout' => 300, 'login_timeout' => 60])['LoginTimeout'] ?? null) === 60);

// The options the non-ASCII and date handling depend on must survive the move
// to a shared builder (§6 of the README).
$frozen = $opt([]);
$check('dates stay strings and the client codepage stays UTF-8',
    ($frozen['ReturnDatesAsStrings'] ?? null) === true && ($frozen['CharacterSet'] ?? null) === 'UTF-8');
$check('database/user/password are passed through',
    ($frozen['Database'] ?? null) === 'erp' && ($frozen['UID'] ?? null) === 'reader'
    && ($frozen['PWD'] ?? null) === 'secret');

// ---- how a params array resolves when the caller says nothing about TLS ----
$norm = static fn (array $p): array => (new ReflectionMethod(ConnectionFactory::class, 'normalize'))
    ->invoke(null, $p);
$bare = ['db_type' => 'sqlserver', 'host' => 'h', 'db' => 'd', 'user' => 'u'];
$resolved = $norm($bare);
$check('the TLS policy comes from the config',
    $resolved['encrypt'] === (bool)Config::get('connections.sqlsrv_encrypt', null)
    && $resolved['trust_server_certificate'] === (bool)Config::get('connections.sqlsrv_trust_server_certificate', null)
    && $resolved['login_timeout'] === (int)Config::get('connections.sqlsrv_login_timeout', 0),
    json_encode([
        $resolved['encrypt'], $resolved['trust_server_certificate'], $resolved['login_timeout'],
    ]));
$explicit = $norm($bare + ['trust_server_certificate' => true, 'encrypt' => false]);
$check('an explicit param still wins', $explicit['trust_server_certificate'] === true
    && $explicit['encrypt'] === false);

// ---- a refused host must answer quickly, not after the query timeout ----
// LoginTimeout is what sqlsrv_connect waits for, so a deliberately small cap
// here must bound the call even though the query timeout says 300 s.
$start = microtime(true);
$error = '';
try {
    ConnectionFactory::fromParams([
        'db_type' => 'sqlserver', 'host' => '127.0.0.1', 'port' => 1,
        'db' => 'nope', 'user' => 'nope', 'password' => 'nope',
        'timeout' => 300, 'login_timeout' => 2,
    ]);
    $error = 'connected (unexpected)';
} catch (\Throwable $e) {
    $error = get_class($e);
}
$elapsed = microtime(true) - $start;
$check('a closed port raises an exception', $error === 'Db\\DbConnectException' || $error === 'RuntimeException', $error);
$check('and gives up after the handshake cap, not the query timeout',
    $elapsed < 8.0, round($elapsed, 2) . ' s');

// ---- shipped defaults (a child with no GATEWAY_* variables) ----
$env = [];
foreach ((array)getenv() as $name => $value) {
    if (!str_starts_with((string)$name, 'GATEWAY_')) {
        $env[(string)$name] = (string)$value;
    }
}
$shipped = null;
$pipes = [];
$err = 'child did not start';
$proc = proc_open([PHP_BINARY, __FILE__, '--dump-shipped'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $env);
if (is_resource($proc)) {
    $out = trim((string)stream_get_contents($pipes[1]));
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $shipped = json_decode($out, true);
    if (!is_array($shipped)) {
        $err = $out . ' ' . $err;
    }
}
$check('shipped connection defaults readable', is_array($shipped), $err);
$check('shipped default: encrypted', ($shipped['sqlsrv_encrypt'] ?? null) === true);
$check('shipped default: certificates validated',
    ($shipped['sqlsrv_trust_server_certificate'] ?? null) === false);
$check('shipped default: 15 s handshake cap', ($shipped['sqlsrv_login_timeout'] ?? null) === 15);

echo PHP_EOL, $failures === 0 ? '=== ALL TLS CHECKS PASSED ===' : "=== {$failures} TLS CHECK(S) FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
