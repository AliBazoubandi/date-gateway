<?php
// ============================================================================
// Data Gateway bootstrap - the ONLY entry include for every entry point
// (public/index.php, cli/*.php, tests/*.php).
// ============================================================================

declare(strict_types=1);

use Core\Config;

// ---- Project constants -------------------------------------------------------
if (!defined('BASE_PATH')) {
    // bootstrap.php lives in src/ => project root is two levels up.
    define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__, 2)));
}

// ---- Autoloader --------------------------------------------------------------
// Prefer Composer's autoloader when present (composer dump-autoload); the SPL
// fallback below maps \Core\Db -> src/Core/Db.php and works with no tooling.
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    $class = ltrim($class, '\\');
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- Configuration -----------------------------------------------------------
\Core\Env::load(BASE_PATH . '/config/.env');
try {
    Config::load(BASE_PATH . '/config/config.php');
    // Refuse to run with a placeholder/retired master key: nothing may
    // encrypt or decrypt until a real key is configured.
    \Services\Encryption::assertUsableKey((string)Config::get('security.master_key', ''));
} catch (\Throwable $e) {
    // Fail loudly and clearly before anything else happens. STDERR only
    // exists under the CLI SAPI: writing to it from Apache/FPM throws
    // "Undefined constant STDERR" *inside this handler*, which replaces the
    // one message the operator needs with a blank 500 and a PHP fatal.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Data Gateway cannot start: ' . $e->getMessage() . PHP_EOL);
    } else {
        error_log('Data Gateway cannot start: ' . $e->getMessage());
    }
    http_response_code(500);
    echo 'Configuration error.';
    exit(1);
}

// ---- Time zone: Asia/Tehran everywhere --------------------------------------
date_default_timezone_set((string)Config::get('app.timezone', 'Asia/Tehran'));
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// ---- Error/exception policy --------------------------------------------------
$debug = (bool)Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');

set_exception_handler(static function (\Throwable $e): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (str_starts_with(\Core\Http::path(), '/api/')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $detail = (bool)Core\Config::get('app.debug', false) ? $e->getMessage() : null;
        // Str::json, not json_encode: a debug-mode exception message can carry
        // bytes from a target database that are not valid UTF-8, and false
        // would echo nothing - a 500 with an empty body and no JSON at all.
        echo Core\Str::json(['error' => ['code' => 'internal_error', 'message' => $detail ?? 'Internal server error']]);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $detail = $debug ? $e->getMessage() : 'An internal error occurred. Check the PHP error log.';
    echo '<h1>Server error</h1><p>' . Core\Str::e($detail) . '</p>';
    exit(1);
});

// ---- Tiny global helpers -----------------------------------------------------
// Metadata DB instance (lazy singleton).
function db(): Core\Db
{
    return Core\Db::instance();
}

// HTML escape.
function e($value): string
{
    return Core\Str::e(is_string($value) ? $value : (string)$value);
}

// Now, Tehran local time as MySQL DATETIME string.
function now_str(): string
{
    return Core\Time::now();
}

// ISO 8601 with +03:30 offset (API timestamps).
function iso8601(?string $mysqlDatetime): ?string
{
    return Core\Time::iso($mysqlDatetime);
}

// Client IP for throttling, logs and audit rows. REMOTE_ADDR unless the peer
// is on admin.trusted_proxies, in which case the proxy's X-Forwarded-For is
// read (see Core\TrustedProxies) — an empty list keeps the old behaviour.
function client_ip(): string
{
    return Core\TrustedProxies::resolve(
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        Core\TrustedProxies::configured()
    );
}

// Guard for destructive test scripts (smoke tests): refuse to run unless the
// dedicated throwaway environment is active. tests\run_all.php sets both;
// tests\run_all.ps1 is the Windows wrapper that calls it.
function test_guard(): void
{
    $dbName = (string)Core\Config::get('metadata_db.database', '');
    if (getenv('GATEWAY_TEST_MODE') !== '1' || stripos($dbName, '_test') === false) {
        fwrite(STDERR,
            'REFUSING to run: this test needs GATEWAY_TEST_MODE=1 and a metadata DB whose name'
            . ' contains "_test" (configured DB: ' . $dbName . ')' . PHP_EOL);
        exit(2);
    }
}

// JSON response + exit.
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    // Substitute (never fail) so invalid data can never blank out a response:
    // a raw json_encode() returning false would echo nothing and still send
    // 200 + application/json. Core\Str::json() always yields a string.
    echo Core\Str::json($payload);
    exit;
}
