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

// ---- Autoloader: \Core\Db -> src/Core/Db.php ---------------------------------
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
    // Fail loudly and clearly before anything else happens.
    fwrite(STDERR, 'Data Gateway cannot start: ' . $e->getMessage() . PHP_EOL);
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
    if (($_SERVER['REQUEST_URI'] ?? '') !== '' && str_starts_with($_SERVER['REQUEST_URI'], '/api/')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $detail = (bool)Core\Config::get('app.debug', false) ? $e->getMessage() : null;
        echo json_encode(['error' => ['code' => 'internal_error', 'message' => $detail ?? 'Internal server error']], JSON_UNESCAPED_SLASHES);
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

// Client IP (REMOTE_ADDR only; do not trust proxy headers by default).
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

// JSON response + exit.
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
