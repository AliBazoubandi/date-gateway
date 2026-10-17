<?php
// ============================================================================
// Data Gateway configuration.
//
// SECURITY: keep this file outside the web root. Apache must point its
// DocumentRoot at the public/ folder (see README). As a fallback, the root
// .htaccess denies everything below the project root except public/.
// ============================================================================

return [
    'app' => [
        'name' => 'Data Gateway',
        // Everything (PHP + MySQL session) runs in Tehran time (+03:30, no DST).
        'timezone' => 'Asia/Tehran',
        // NEVER enable on a live system; shows errors to the browser.
        // Override with GATEWAY_DEBUG=1 in config/.env for development.
        'debug' => (string)\Core\Env::get('GATEWAY_DEBUG', '0') === '1',
        // Public base URL used to build download links, e.g. 'http://gw.internal'.
        // Empty = build from the incoming Host header. (config/.env: GATEWAY_BASE_URL)
        'base_url' => (string)\Core\Env::get('GATEWAY_BASE_URL', ''),
    ],

    // Metadata database (the MariaDB/MySQL that ships with XAMPP).
    // Defaults below are XAMPP's; for production create a dedicated user, e.g.
    //   CREATE USER 'gateway'@'localhost' IDENTIFIED BY 'strong-password';
    //   GRANT SELECT,INSERT,UPDATE,DELETE,LOCK TABLES ON data_gateway.* TO 'gateway'@'localhost';
    'metadata_db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'data_gateway',
        'user' => 'root',
        'password' => (string)\Core\Env::get('GATEWAY_DB_PASSWORD', ''),
    ],

    'security' => [
        // AES-256-GCM master key used to encrypt connection passwords and
        // per-call download tokens. MUST be 64 hex chars (32 bytes) and must
        // never be committed: set it in config/.env as GATEWAY_MASTER_KEY.
        // Generate once with:  php -r "echo bin2hex(random_bytes(32));"
        // Changing it later makes every stored secret unreadable.
        'master_key' => (string)\Core\Env::get('GATEWAY_MASTER_KEY', str_repeat('0', 64)),
    ],

    'storage' => [
        // Absolute path to the storage area (result JSON files + instant cache).
        // Default: <project>/storage  - outside the web root when DocumentRoot=public/
        'dir' => null,          // null = <project root>/storage
        // Sub-paths, relative to dir:
        'results_subdir' => 'results',
        'cache_subdir' => 'cache',
    ],

    'limits' => [
        // Per-target-connection cap for concurrently RUNNING delayed executions.
        'connection_concurrency' => 2,
        'instant' => [
            // Sane upper bounds enforced by the admin UI for instant requests.
            'max_timeout_seconds' => 60,
            'max_rows' => 20000,
            'defaults' => ['timeout_seconds' => 30, 'max_rows' => 5000],
        ],
        // 429 response for instant mode when the per-connection cap is busy.
        'rate_limited_retry_after' => 5,
    ],

    'worker' => [
        // How many executions one worker pass may process (stops a pass from
        // growing unboundedly while new requests keep arriving).
        'max_per_run' => 50,
        // A delayed 'running' execution older than this is treated as a crashed
        // worker's leftover and is failed by the next pass. Must comfortably
        // exceed the longest request timeout you allow.
        'stale_running_minutes' => 60,
    ],

    'downloads' => [
        // Bytes per chunk when streaming result files to clients.
        'chunk_size' => 65536,
    ],

    'admin' => [
        'session_name' => 'datagateway_admin',
        'max_attempts' => 5,        // failed logins before lockout
        'lockout_minutes' => 15,
        // The request test-run button samples the SQL with its own tight
        // limits (independent of the request's configured timeout/rows).
        'test_run' => ['max_rows' => 20, 'max_seconds' => 10],
    ],

    'webhook' => [
        'max_attempts' => 5,   // retries AFTER the initial send (so up to 6 sends)
        // Minutes between attempts (used in order as backoff steps).
        'backoff_minutes' => [1, 2, 5, 10, 30],
        'timeout_seconds' => 10,
        // Deliveries processed per pass (cli/worker.php + cli/webhooks.php).
        'max_per_pass' => 10,
    ],

    // Optional: connections used by tests/driver_test.php (see that file).
    'test_connections' => [],

    'connections' => [
        // SQL Server encryption: true = accept self-signed certificates (typical
        // intranet setups), false = enforce certificate validation.
        'sqlsrv_trust_server_certificate' => true,
    ],
];
