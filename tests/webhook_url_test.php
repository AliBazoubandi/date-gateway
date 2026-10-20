<?php
declare(strict_types=1);
// Unit tests for the webhook receiver SSRF gate (Services\WebhookUrl).
//
// Each policy is exercised in a CHILD process: config/config.php is read once
// at bootstrap, so the only way to test several GATEWAY_WEBHOOK_* combinations
// is to set them with putenv() and spawn `--check` on this file, which prints
// one JSON line per URL it was given. The parent asserts on that JSON.
//
// No internet is required: literal addresses are classified from their own
// bytes, and the allowlist is checked before any lookup. The two cases that
// need a name to resolve (localhost) run only when this host actually resolves
// it to an IPv4 address, and then assert exactly which address got pinned.
//
// Usage: php tests\webhook_url_test.php
//        php tests\webhook_url_test.php --check https://10.0.0.5/hook ...

use Core\Config;
use Core\IpRange;
use Core\Str;
use Services\WebhookUrl;

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

if (($argv[1] ?? '') === '--check') {
    foreach (array_slice($argv, 2) as $url) {
        $r = WebhookUrl::check((string)$url);
        echo json_encode([
            'url' => (string)$url,
            'error' => $r['error'],
            'retryable' => (bool)$r['retryable'],
            'ip' => $r['ip'],
            'resolve' => $r['resolve'],
        ]), PHP_EOL;
    }
    exit(0);
}

if (($argv[1] ?? '') === '--dump-config') {
    // Raw values, so a missing key shows up as 'missing' instead of false.
    echo json_encode([
        'allow_http' => Config::get('webhook.allow_http', 'missing'),
        'allow_loopback' => Config::get('webhook.allow_loopback', 'missing'),
        'allow_private_hosts' => Config::get('webhook.allow_private_hosts', 'missing'),
        'allowed_hosts' => Config::get('webhook.allowed_hosts', 'missing'),
    ]), PHP_EOL;
    exit(0);
}

$failures = 0;
$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label,
        $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

// ------------------- policy runner -------------------
$policyKeys = [
    'GATEWAY_WEBHOOK_ALLOW_HTTP',
    'GATEWAY_WEBHOOK_ALLOW_LOOPBACK',
    'GATEWAY_WEBHOOK_ALLOW_PRIVATE_HOSTS',
    'GATEWAY_WEBHOOK_ALLOWED_HOSTS',
];

/** @param array<string,string> $env */
$apply = static function (array $env) use ($policyKeys): void {
    foreach ($policyKeys as $key) {
        // Unset the keys this policy does not set, so the previous run's
        // environment cannot leak into it.
        putenv(array_key_exists($key, $env) ? $key . '=' . $env[$key] : $key);
    }
};

/**
 * Spawn this file as a child with the given argv and return its stdout lines.
 * null means the child failed, which is reported as its own check.
 *
 * @param list<string> $cmd
 * @return list<string>|null
 */
$child = static function (array $cmd, string $label) use ($check): ?array {
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        $check($label . ': child started', false, 'proc_open failed');
        return null;
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0 || $stderr !== '') {
        $check($label . ': child ran clean', false, "exit={$exit} " . Str::truncate($stderr, 200));
        return null;
    }
    return array_values(array_filter(explode("\n", $stdout), static fn (string $l): bool => $l !== ''));
};

/**
 * Run one policy in a child process.
 *
 * @param array<string,string> $env
 * @param list<array{url:string,error?:string,ip?:string,resolve?:list<string>}> $cases
 */
$run = static function (string $label, array $env, array $cases) use ($apply, $child, $check): void {
    echo PHP_EOL, '--- ', $label, ' ---', PHP_EOL;
    $apply($env);
    $cmd = [PHP_BINARY, __FILE__, '--check'];
    foreach ($cases as $case) {
        $cmd[] = $case['url'];
    }
    $lines = $child($cmd, $label);
    if ($lines === null) {
        return;
    }
    if (count($lines) !== count($cases)) {
        $check($label . ': one result per URL', false, count($lines) . '/' . count($cases));
        return;
    }
    foreach ($cases as $index => $case) {
        $got = json_decode($lines[$index], true);
        if (!is_array($got)) {
            $check($case['url'], false, 'child printed ' . $lines[$index]);
            continue;
        }
        $wantError = (string)($case['error'] ?? '');
        $error = (string)($got['error'] ?? '');
        if ($wantError === '') {
            $pass = $error === '';
            $detail = $pass ? '' : "rejected: {$error}";
        } else {
            $pass = $error !== '' && str_contains($error, $wantError);
            $detail = $pass ? '' : "wanted '{$wantError}', got '{$error}'";
        }
        if ($pass && array_key_exists('ip', $case)) {
            $pass = (string)($got['ip'] ?? '') === $case['ip'];
            $detail = $pass ? '' : "ip '{$got['ip']}', wanted '{$case['ip']}'";
        }
        if ($pass && array_key_exists('resolve', $case)) {
            $pass = (array)($got['resolve'] ?? []) === $case['resolve'];
            $detail = $pass ? '' : 'resolve ' . json_encode($got['resolve'])
                . ', wanted ' . json_encode($case['resolve']);
        }
        if ($pass && array_key_exists('retryable', $case)) {
            $pass = (bool)($got['retryable'] ?? false) === $case['retryable'];
            $detail = $pass ? '' : 'retryable ' . json_encode($got['retryable'] ?? null)
                . ', wanted ' . json_encode($case['retryable']);
        }
        $check($case['url'] . ($wantError === '' ? ' [accepted]' : ' [rejected]'), $pass, $detail);
    }
};

// ------------------- production defaults -------------------
// https only, loopback refused, private/LAN allowed (this deployment's
// receivers live on the LAN), no host allowlist.
$run('default policy', [], [
    ['url' => 'https://93.184.216.34/hook', 'ip' => '93.184.216.34', 'resolve' => []],
    ['url' => 'HTTPS://93.184.216.34/hook'],
    ['url' => 'https://10.0.0.5:8443/hook', 'ip' => '10.0.0.5', 'resolve' => []],
    ['url' => 'https://192.168.1.20/hook'],
    ['url' => 'https://172.16.5.4/hook'],
    ['url' => 'https://[fd00::1]/hook', 'ip' => 'fd00::1'],
    ['url' => 'http://93.184.216.34/hook', 'error' => 'https', 'retryable' => false],
    ['url' => 'https://127.0.0.1/hook', 'error' => 'loopback', 'retryable' => false],
    ['url' => 'https://127.0.0.53/hook', 'error' => 'loopback'],
    ['url' => 'https://[::1]/hook', 'error' => 'loopback'],
    // IPv4-mapped IPv6: the OS and curl route ::ffff:a.b.c.d to the embedded
    // IPv4 address, so the policy must classify the embedded one (BUG 3).
    ['url' => 'https://[::ffff:127.0.0.1]/hook', 'error' => 'loopback'],
    ['url' => 'https://[::ffff:7f00:1]/hook', 'error' => 'loopback'],
    ['url' => 'https://[::ffff:169.254.169.254]/latest/meta-data/', 'error' => 'blocked'],
    ['url' => 'https://[::ffff:10.0.0.5]/hook', 'ip' => '::ffff:10.0.0.5'],
    ['url' => 'https://[::ffff:192.168.1.20]/hook', 'ip' => '::ffff:192.168.1.20'],
    ['url' => 'https://[::ffff:8.8.8.8]/hook', 'ip' => '::ffff:8.8.8.8'],
    ['url' => 'https://[::ffff:100.64.0.1]/hook', 'error' => 'blocked'],
    ['url' => 'https://[::ffff:0.0.0.0]/hook', 'error' => 'blocked'],
    ['url' => 'https://169.254.169.254/latest/meta-data/', 'error' => 'blocked'],
    ['url' => 'https://169.254.1.1/hook', 'error' => 'blocked'],
    ['url' => 'https://100.64.0.1/hook', 'error' => 'blocked'],
    ['url' => 'https://0.0.0.0/hook', 'error' => 'blocked'],
    ['url' => 'https://224.0.1.1/hook', 'error' => 'blocked'],
    ['url' => 'https://240.0.0.1/hook', 'error' => 'blocked'],
    ['url' => 'https://[fe80::1]/hook', 'error' => 'blocked'],
    ['url' => 'file:///etc/passwd', 'error' => 'https://'],
    ['url' => 'ftp://hooks.example.test/gw', 'error' => 'https://'],
    ['url' => 'not-a-url', 'error' => 'full URL'],
    ['url' => '', 'error' => 'full URL'],
    ['url' => 'https:///hook', 'error' => 'full URL'],
    ['url' => 'https:/hook', 'error' => 'no host'],
    ['url' => 'https://user:pw@93.184.216.34/hook', 'error' => 'credentials'],
    // A name that does not resolve is a TRANSIENT condition (DNS), so the
    // delivery gate reports it retryable; the policy refusals stay permanent.
    ['url' => 'https://gateway-host-does-not-exist.invalid/hook', 'error' => 'does not resolve', 'retryable' => true],
    ['url' => 'https://' . str_repeat('a', 70) . '.test/hook?x=' . str_repeat('y', 480), 'error' => 'too long'],
]);

// ------------------- LAN receivers turned off -------------------
$run('private hosts refused', ['GATEWAY_WEBHOOK_ALLOW_PRIVATE_HOSTS' => '0'], [
    ['url' => 'https://93.184.216.34/hook', 'ip' => '93.184.216.34'],
    ['url' => 'https://10.0.0.5/hook', 'error' => 'private-network'],
    ['url' => 'https://172.16.5.4/hook', 'error' => 'private-network'],
    ['url' => 'https://192.168.1.20/hook', 'error' => 'private-network'],
    ['url' => 'https://[fd00::1]/hook', 'error' => 'private-network'],
    ['url' => 'https://[::ffff:10.0.0.5]/hook', 'error' => 'private-network'],
    ['url' => 'https://[::ffff:127.0.0.1]/hook', 'error' => 'loopback'],
    ['url' => 'https://127.0.0.1/hook', 'error' => 'loopback'],
    ['url' => 'https://169.254.169.254/hook', 'error' => 'blocked'],
]);

// ------------------- the test-environment shape (run_all.php) -------------------
// What webhook_smoke's receiver needs: http on 127.0.0.1. Reserved ranges must
// stay blocked even with both relaxations on.
$run('http + loopback allowed', [
    'GATEWAY_WEBHOOK_ALLOW_HTTP' => '1',
    'GATEWAY_WEBHOOK_ALLOW_LOOPBACK' => '1',
], [
    ['url' => 'http://127.0.0.1:9099/hook/gw', 'ip' => '127.0.0.1', 'resolve' => []],
    ['url' => 'http://[::1]/hook', 'ip' => '::1'],
    ['url' => 'http://169.254.169.254/hook', 'error' => 'blocked'],
    ['url' => 'http://100.64.0.1/hook', 'error' => 'blocked'],
    ['url' => 'http://10.0.0.5/hook', 'ip' => '10.0.0.5'],
    ['url' => 'https://93.184.216.34/hook', 'ip' => '93.184.216.34'],
]);

// ------------------- host allowlist -------------------
// Exact, lowercase names only - no suffix or wildcard matching, and checked
// before any lookup.
$run('allowlist', ['GATEWAY_WEBHOOK_ALLOWED_HOSTS' => ' 93.184.216.34, hooks.lan.example '], [
    ['url' => 'https://93.184.216.34/hook', 'ip' => '93.184.216.34'],
    ['url' => 'https://10.0.0.5/hook', 'error' => 'allowed_hosts'],
    ['url' => 'https://hooks.lan.example/hook', 'error' => 'does not resolve'],
    ['url' => 'https://sub.hooks.lan.example/hook', 'error' => 'allowed_hosts'],
    // Upper-case is lowercased before the list is consulted, so this passes the
    // allowlist and only then fails on lookup.
    ['url' => 'https://HOOKS.LAN.EXAMPLE/hook', 'error' => 'does not resolve'],
]);

// ------------------- name resolution + pinning -------------------
// Only when this host actually gives localhost an IPv4 record: proves a name is
// classified from its resolved address and pinned to it for the send.
$localhostV4 = gethostbynamel('localhost') ?: [];
if ($localhostV4 === []) {
    echo PHP_EOL, '--- localhost naming cases skipped (no A record here) ---', PHP_EOL;
} else {
    $ip = (string)$localhostV4[0];
    $run('localhost resolves to ' . $ip, [], [
        ['url' => 'https://localhost/hook', 'error' => 'loopback'],
    ]);
    $run('localhost allowed', [
        'GATEWAY_WEBHOOK_ALLOW_LOOPBACK' => '1',
        'GATEWAY_WEBHOOK_ALLOWED_HOSTS' => 'localhost',
    ], [
        ['url' => 'https://localhost/hook', 'ip' => $ip, 'resolve' => ["localhost:443:{$ip}"]],
        ['url' => 'http://localhost:9099/hook', 'error' => 'https'],
        ['url' => 'https://localhost:8443/hook', 'resolve' => ["localhost:8443:{$ip}"]],
        ['url' => 'https://LOCALHOST/hook', 'ip' => $ip],
    ]);
}

// ------------------- the mapping rule itself -------------------
// WebhookUrl classifies through IpRange::normalize; pin the normalization so a
// future edit cannot quietly turn mapped addresses back into "unknown".
$check('normalize: mapped loopback -> v4', IpRange::normalize('::ffff:127.0.0.1') === '127.0.0.1');
$check('normalize: mapped metadata -> v4', IpRange::normalize('::ffff:169.254.169.254') === '169.254.169.254');
$check('normalize: the hex spelling of the same bytes', IpRange::normalize('::ffff:7f00:1') === '127.0.0.1');
$check('normalize: a plain v4 address is unchanged', IpRange::normalize('10.0.0.5') === '10.0.0.5');
$check('normalize: ::1 is loopback as itself', IpRange::normalize('::1') === '::1');
$check('normalize: a v6 ULA is unchanged', IpRange::normalize('fd00::1') === 'fd00::1');
$check('normalize: garbage is null', IpRange::normalize('not-an-ip') === null);
$check('normalize: empty is null', IpRange::normalize('  ') === null);

// The gate's own config defaults, read in a child that has no webhook
// overrides in its environment (this process inherited the runner's relaxed
// ones, and config is loaded once at bootstrap).
$apply([]);
$dumped = $child([PHP_BINARY, __FILE__, '--dump-config'], 'default config');
$fresh = $dumped === null || $dumped === [] ? [] : (array)json_decode($dumped[0], true);
$check('default config: https only', ($fresh['allow_http'] ?? null) === false,
    json_encode($fresh['allow_http'] ?? 'absent'));
$check('default config: loopback blocked', ($fresh['allow_loopback'] ?? null) === false,
    json_encode($fresh['allow_loopback'] ?? 'absent'));
$check('default config: private/LAN allowed', ($fresh['allow_private_hosts'] ?? null) === true,
    json_encode($fresh['allow_private_hosts'] ?? 'absent'));
$check('default config: no host allowlist', ($fresh['allowed_hosts'] ?? null) === [],
    json_encode($fresh['allowed_hosts'] ?? 'absent'));

echo PHP_EOL,
    $failures === 0 ? '=== WEBHOOK URL TEST PASS ===' : "=== WEBHOOK URL TEST: {$failures} FAILED ===",
    PHP_EOL;
exit($failures === 0 ? 0 : 1);
