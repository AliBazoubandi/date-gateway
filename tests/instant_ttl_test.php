<?php
// Unit test for the instant cache window clamp: a request's window can never
// exceed the lifetime of the result file a hit replays
// (limits.instant.result_ttl_minutes).

if (($argv[1] ?? '') === '--dump-shipped') {
    require __DIR__ . '/../src/Core/Env.php';
    require __DIR__ . '/../src/Core/IpRange.php';
    $cfg = require __DIR__ . '/../config/config.php';
    $ttl = max(1, (int)($cfg['limits']['instant']['result_ttl_minutes'] ?? 0));
    echo json_encode(['result_ttl_minutes' => $ttl, 'cache_bound_seconds' => $ttl * 60]), PHP_EOL;
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

// Swap the loaded config per case (test-only; Config has no public setter).
$prop = (new ReflectionClass(\Core\Config::class))->getProperty('data');
$prop->setAccessible(true);
$original = $prop->getValue();
$withTtl = static function (int|string $minutes) use ($prop, $original): void {
    $data = $original;
    $data['limits']['instant']['result_ttl_minutes'] = $minutes;
    $prop->setValue(null, $data);
};
$window = static fn (array $request): int => \Services\InstantExecutor::cacheWindowSeconds($request);

$withTtl(60);
$check('60-minute lifetime reads back', \Services\InstantExecutor::resultTtlMinutes() === 60);
$check('24 h window collapses to the file lifetime',
    $window(['instant_cache_seconds' => 86400]) === 3600);
$check('one second past the lifetime is trimmed',
    $window(['instant_cache_seconds' => 3601]) === 3600);
$check('exact lifetime is kept', $window(['instant_cache_seconds' => 3600]) === 3600);
$check('short window is untouched', $window(['instant_cache_seconds' => 30]) === 30);
$check('string from the driver is accepted', $window(['instant_cache_seconds' => '86400']) === 3600);
$check('0 disables the cache', $window(['instant_cache_seconds' => 0]) === 0);
$check('negative disables the cache', $window(['instant_cache_seconds' => -5]) === 0);
$check('missing column disables the cache', $window([]) === 0);

$withTtl(1);
$check('1-minute lifetime caps at 60 s', $window(['instant_cache_seconds' => 120]) === 60);
$withTtl(0);
$check('a 0 lifetime still serves 60 s (never 0)', $window(['instant_cache_seconds' => 120]) === 60);
$withTtl(1440);
$check('24 h lifetime serves a 24 h window', $window(['instant_cache_seconds' => 86400]) === 86400);

$prop->setValue(null, $original);

// Shipped default: read from a child that sees no GATEWAY_* variables, so the
// packaged value is what is checked (the 24 h window / 60 min file mismatch
// this clamp fixes is the shipped one).
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
$check('shipped instant defaults readable', is_array($shipped), $err);
$check('shipped result file lifetime is 60 minutes', ($shipped['result_ttl_minutes'] ?? null) === 60,
    json_encode($shipped));
$check('shipped lifetime bounds the window at 3600 s', ($shipped['cache_bound_seconds'] ?? null) === 3600);

echo $failures === 0 ? '=== INSTANT TTL TEST PASS ===' : "=== INSTANT TTL TEST FAIL ({$failures}) ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
