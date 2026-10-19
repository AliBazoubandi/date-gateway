<?php
// Unit test for Core\IpRange + Core\TrustedProxies: the address-range math the
// webhook SSRF gate and the admin trusted-proxy list share, and the address
// client_ip() ends up recording.
//
// The shipped admin defaults are read from a child process that sees no
// GATEWAY_* variables (and never loads config/.env), so what is asserted is the
// fallback written in config/config.php rather than this run's environment.

declare(strict_types=1);

use Core\Config;
use Core\IpRange;
use Core\TrustedProxies;

if (($argv[1] ?? '') === '--dump-shipped') {
    require __DIR__ . '/../src/Core/Env.php';
    require __DIR__ . '/../src/Core/IpRange.php';
    $cfg = require __DIR__ . '/../config/config.php';
    echo json_encode([
        'idle' => $cfg['admin']['session_idle_minutes'],
        'proxies' => $cfg['admin']['trusted_proxies'],
    ]), PHP_EOL;
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

// --------------------------- IpRange::inCidr ---------------------------
$in = static fn (string $ip, string $cidr): string => IpRange::inCidr($ip, $cidr) ? 'in' : 'out';
$check('v8 network holds its addresses', $in('10.0.5.9', '10.0.0.0/8') === 'in', $in('10.0.5.9', '10.0.0.0/8'));
$check('v8 network refuses the next block', $in('11.0.0.1', '10.0.0.0/8') === 'out');
$check('boundary just below a network', $in('9.255.255.255', '10.0.0.0/8') === 'out');
$check('/24 inside matches', $in('192.168.0.10', '192.168.0.0/24') === 'in');
$check('/24 next block refused', $in('192.168.1.10', '192.168.0.0/24') === 'out');
$check('/30 partial byte mask', $in('198.51.100.5', '198.51.100.4/30') === 'in');
$check('/30 outside the small range', $in('198.51.100.7', '198.51.100.8/30') === 'out');
$check('/31 is two addresses', $in('192.0.2.2', '192.0.2.2/31') === 'in' && $in('192.0.2.3', '192.0.2.2/31') === 'in'
    && $in('192.0.2.4', '192.0.2.2/31') === 'out');
$check('bare address compares as /32', $in('127.0.0.1', '127.0.0.1') === 'in' && $in('127.0.0.2', '127.0.0.1') === 'out');
$check('bare IPv6 compares as /128', $in('::1', '::1') === 'in' && $in('::2', '::1') === 'out');
$check('/0 matches everything', $in('8.8.8.8', '0.0.0.0/0') === 'in');
$check('v6 ULA range', $in('fd12::5', 'fc00::/7') === 'in');
$check('v6 link-local is not ULA', $in('fe80::1', 'fc00::/7') === 'out');
$check('v6 /64 prefix', $in('2001:db8:abcd::9', '2001:db8:abcd::/48') === 'in'
    && $in('2001:db8:abce::9', '2001:db8:abcd::/48') === 'out');
$check('v4 address never matches a v6 range', $in('10.0.0.1', 'fc00::/7') === 'out');
$check('v6-mapped v4 does not sneak into a v4 range', $in('::ffff:10.0.0.1', '10.0.0.0/8') === 'out',
    'IPv4-only classification by design');
$check('over-long v4 prefix behaves as /32', $in('10.0.0.0', '10.0.0.0/33') === 'in'
    && $in('10.0.0.1', '10.0.0.0/33') === 'out');
$check('garbage address is in nothing', $in('not-an-ip', '10.0.0.0/8') === 'out' && $in('', '10.0.0.0/8') === 'out');
$check('garbage range matches nobody', $in('10.0.0.1', 'garbage') === 'out' && $in('10.0.0.1', '') === 'out');

$any = static fn (string $ip, array $ranges): string => IpRange::inRanges($ip, $ranges) ? 'in' : 'out';
$check('inRanges scans the list', $any('172.20.0.1', ['10.0.0.0/8', '172.16.0.0/12']) === 'in'
    && $any('172.32.0.1', ['10.0.0.0/8', '172.16.0.0/12']) === 'out');
$check('inRanges with no ranges', $any('10.0.0.1', []) === 'out');

$list = static fn (string $csv): array => IpRange::parseList($csv);
$check('parseList trims, lowercases, drops empties',
    $list(' 127.0.0.1 , FF00::/8 ,, 10.0.0.0/8 ') === ['127.0.0.1', 'ff00::/8', '10.0.0.0/8'],
    json_encode($list(' 127.0.0.1 , FF00::/8 ,, 10.0.0.0/8 ')));
$check('parseList of an empty string', $list('') === [] && $list(' , ,') === []);

// --------------------------- TrustedProxies::hops ---------------------------
$h = static fn (string $header): ?array => TrustedProxies::hops($header);
$check('hops splits and trims', $h('203.0.113.7, 10.0.0.5') === ['203.0.113.7', '10.0.0.5']);
$check('hops lowercases', $h('2001:DB8::1') === ['2001:db8::1']);
$check('hops unbrackets a v6 hop', $h('[2001:db8::1], [::1]') === ['2001:db8::1', '::1']);
$check('hops of an empty header', $h('') === [] && $h('   ') === []);
$check('hops refuses a port suffix', $h('203.0.113.7:8080') === null);
$check('hops refuses a non-address token', $h('203.0.113.7, unknown') === null
    && $h('for=203.0.113.7') === null && $h('203.0.113.7;10.0.0.5') === null);

// --------------------------- TrustedProxies::isTrusted ---------------------------
$trusted = ['127.0.0.1', '10.0.0.0/8', '::1/128'];
$check('exact hop is trusted', TrustedProxies::isTrusted('127.0.0.1', $trusted));
$check('neighbour of an exact hop is not', !TrustedProxies::isTrusted('127.0.0.2', $trusted));
$check('CIDR hop is trusted', TrustedProxies::isTrusted('10.4.4.4', $trusted));
$check('v6 hop is trusted', TrustedProxies::isTrusted('::1', $trusted));
$check('empty or junk is never trusted', !TrustedProxies::isTrusted('', $trusted)
    && !TrustedProxies::isTrusted('localhost', $trusted));
$check('an empty list trusts nobody', !TrustedProxies::isTrusted('127.0.0.1', []));

// --------------------------- TrustedProxies::resolve ---------------------------
$r = static fn (string $peer, string $header, array $list): string => TrustedProxies::resolve($peer, $header, $list);
$check('no proxies configured: header ignored', $r('127.0.0.1', '8.8.8.8', []) === '127.0.0.1');
$check('untrusted peer: header ignored', $r('203.0.113.7', '8.8.8.8', ['10.0.0.0/8']) === '203.0.113.7');
$check('trusted peer with one hop: that hop', $r('127.0.0.1', '203.0.113.7', ['127.0.0.1']) === '203.0.113.7');
// The whole point of the rightmost rule: everything left of the proxy is text
// the client typed, so the chain is read from the right.
$check('spoofed leftmost hop is not believed',
    $r('127.0.0.1', '1.2.3.4, 203.0.113.7, 198.51.100.9', ['127.0.0.1']) === '198.51.100.9',
    'leftmost would have been 1.2.3.4');
$check('rightmost untrusted hop wins even mid-chain',
    $r('10.0.0.9', '203.0.113.7, 192.0.2.66', ['10.0.0.0/8']) === '192.0.2.66');
$check('fully internal chain: back to the peer',
    $r('10.0.0.9', '10.1.1.1, 10.2.2.2', ['10.0.0.0/8', '127.0.0.1']) === '10.0.0.9');
$check('garbage header falls back to the peer', $r('127.0.0.1', '1.2.3.4:77, x', ['127.0.0.1']) === '127.0.0.1');
$check('empty header falls back to the peer', $r('127.0.0.1', '', ['127.0.0.1']) === '127.0.0.1');
$check('v6 proxy hop is resolved', $r('::1', '2001:db8::1', ['::1']) === '2001:db8::1');
$check('no peer address: nothing to resolve', $r('', '8.8.8.8', ['127.0.0.1']) === '');

// --------------------------- configured() + client_ip() ---------------------------
// Swap the loaded config per case (test-only; Config has no public setter).
$prop = (new ReflectionClass(Config::class))->getProperty('data');
$prop->setAccessible(true);
$original = $prop->getValue();
$withProxies = static function (array $proxies) use ($prop, $original): void {
    $data = $original;
    $data['admin'] = array_merge($data['admin'] ?? [], ['trusted_proxies' => $proxies]);
    $prop->setValue(null, $data);
};

$withProxies([' 127.0.0.1 ', 'FF00::/8', '']);
$check('configured() re-normalizes what config gave it',
    TrustedProxies::configured() === ['127.0.0.1', 'ff00::/8'], json_encode(TrustedProxies::configured()));
$withProxies([]);
$check('configured() with an empty list', TrustedProxies::configured() === []);

$peer = $_SERVER['REMOTE_ADDR'] ?? null;
$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.7';

$withProxies([]);
$check('client_ip() without proxies stays on REMOTE_ADDR', client_ip() === '127.0.0.1', client_ip());
$withProxies(['127.0.0.1']);
$check('client_ip() through a named proxy uses the real client', client_ip() === '203.0.113.7', client_ip());
$withProxies(['192.0.2.1']);
$check('client_ip() ignores a header from an unnamed peer', client_ip() === '127.0.0.1', client_ip());

$prop->setValue(null, $original);
if ($peer === null) {
    unset($_SERVER['REMOTE_ADDR']);
} else {
    $_SERVER['REMOTE_ADDR'] = $peer;
}
if ($xff === null) {
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
} else {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
}

// ---------------------- shipped defaults (clean child) ----------------------
$env = [];
foreach ((array)getenv() as $name => $value) {
    if (!str_starts_with((string)$name, 'GATEWAY_')) {
        $env[(string)$name] = (string)$value;
    }
}
$shipped = null;
$pipes = [];
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
} else {
    $err = 'child did not start';
}
$check('shipped defaults readable from a GATEWAY_* free child', is_array($shipped), $err ?? '');
$check('default idle timeout is 30 minutes', ($shipped['idle'] ?? null) === 30,
    json_encode($shipped['idle'] ?? null));
$check('default trusted proxy list is empty', ($shipped['proxies'] ?? null) === [],
    json_encode($shipped['proxies'] ?? null));

// What this process actually runs with: the shipped default when nothing
// overrides it, the override otherwise. Both are asserted, never skipped.
$idleOverride = getenv('GATEWAY_ADMIN_SESSION_IDLE_MINUTES');
if ($idleOverride === false) {
    $check('effective idle timeout is the shipped default',
        (int)Config::get('admin.session_idle_minutes', -1) === 30,
        (string)(int)Config::get('admin.session_idle_minutes', -1));
} else {
    $check('effective idle timeout follows GATEWAY_ADMIN_SESSION_IDLE_MINUTES',
        (int)Config::get('admin.session_idle_minutes', -1) === (int)$idleOverride,
        (string)(int)Config::get('admin.session_idle_minutes', -1));
}
$proxyOverride = getenv('GATEWAY_TRUSTED_PROXIES');
if ($proxyOverride === false) {
    $check('no proxy is trusted by default', Config::get('admin.trusted_proxies') === []);
} else {
    $check('GATEWAY_TRUSTED_PROXIES reaches the config',
        Config::get('admin.trusted_proxies') === IpRange::parseList((string)$proxyOverride),
        json_encode(Config::get('admin.trusted_proxies')));
}

echo PHP_EOL, $failures === 0 ? '=== ALL PROXY CHECKS PASSED ===' : "=== {$failures} PROXY CHECK(S) FAILED ===", PHP_EOL;
exit($failures === 0 ? 0 : 1);
