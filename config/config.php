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
    // Production: create the dedicated user with sql/create_user.sql (SELECT,
    // INSERT, UPDATE, DELETE, LOCK TABLES on data_gateway only) and set its
    // password as GATEWAY_DB_PASSWORD in config/.env. Do NOT ship with root.
    // Every part can be overridden via the environment (tests point the
    // gateway at a throwaway DB with GATEWAY_DB_NAME):
    'metadata_db' => [
        'host' => (string)\Core\Env::get('GATEWAY_DB_HOST', '127.0.0.1'),
        'port' => (int)\Core\Env::get('GATEWAY_DB_PORT', '3306'),
        'database' => (string)\Core\Env::get('GATEWAY_DB_NAME', 'data_gateway'),
        'user' => (string)\Core\Env::get('GATEWAY_DB_USER', 'gateway'),
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
        // Override with GATEWAY_STORAGE_DIR (the test runner points it at a
        // throwaway directory so a run can never touch real result files).
        'dir' => \Core\Env::get('GATEWAY_STORAGE_DIR'),   // null = <project root>/storage
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
            // How long a successful instant result FILE stays downloadable
            // (via ?download=1 on the submission or the link in the JSON
            // response). The inline data in the POST response is unaffected;
            // instant requests always use this value (requests.ttl_minutes is
            // 0 by design for instant). Swept to 'expired' by cleanup.
            'result_ttl_minutes' => 60,
            // Hard cap on the INLINE instant payload: rows are buffered in
            // memory to build the JSON response, so an unbounded result could
            // OOM the PHP process. Exceeding it fails the run with 413
            // result_too_large. The streamed result FILE is capped separately
            // by limits.max_result_bytes.
            'max_result_bytes' => (int)\Core\Env::get('GATEWAY_INSTANT_MAX_BYTES', 16 * 1024 * 1024),
        ],
        // Hard cap on a streamed result file (delayed worker and instant
        // file). ResultWriter aborts the run past this size instead of
        // filling the disk (413 / failed execution).
        'max_result_bytes' => (int)\Core\Env::get('GATEWAY_MAX_RESULT_BYTES', 512 * 1024 * 1024),
        // Floor on the free space of the volume holding storage/, checked before
        // a NEW result file is opened. A delayed execution that finds less than
        // this free fails with error_code storage_low instead of writing until
        // the volume fills mid-query. 0 disables the check.
        'min_free_disk_bytes' => (int)\Core\Env::get('GATEWAY_MIN_FREE_DISK_BYTES', 2 * 1024 * 1024 * 1024),
        // Default max_rows suggested for new DELAYED requests (admin form).
        'default' => ['max_rows' => 100000],
        // Optional per-client submit throttle. 0 = disabled. When enabled, a
        // client may start at most N submissions per fixed window
        // (limits.client_submit_window_seconds); extra ones get 429 +
        // Retry-After with the seconds left in that window (logged as
        // 'rate_limited'). Counters live in client_rate_limits, not in
        // request_logs, so pruning logs cannot loosen the cap.
        // Production: 60 submissions per 60s window per client.
        'client_submit_window_seconds' => 60,
        'client_submit_max' => (int)\Core\Env::get('GATEWAY_CLIENT_SUBMIT_MAX', 60),
        // 429 response for instant mode when the per-connection cap is busy.
        'rate_limited_retry_after' => 5,
    ],

    // Optional housekeeping, all off by default: an upgrade must not delete
    // history nobody asked to lose. Every knob is a day window pruned in
    // batches during each cleanup pass; 0 = keep rows forever.
    // Recommended production values (see README): request_logs 90, calls 365,
    // executions 365, webhook_deliveries 30, audit 0 (compliance history).
    'cleanup' => [
        // Deprecated alias for retention.request_logs_days, kept so existing
        // deployments that already set it keep pruning request_logs.
        'log_retention_days' => 0,
        'retention' => [
            'request_logs_days' => (int)\Core\Env::get('GATEWAY_RETENTION_REQUEST_LOGS_DAYS', 0),
            'calls_days' => (int)\Core\Env::get('GATEWAY_RETENTION_CALLS_DAYS', 0),
            'executions_days' => (int)\Core\Env::get('GATEWAY_RETENTION_EXECUTIONS_DAYS', 0),
            'webhook_deliveries_days' => (int)\Core\Env::get('GATEWAY_RETENTION_DELIVERIES_DAYS', 0),
            'audit_days' => (int)\Core\Env::get('GATEWAY_RETENTION_AUDIT_DAYS', 0),
        ],
        // A webhook delivery only leaves 'pending' when the client is
        // configured and reachable; cli/check.php warns once the backlog of
        // waiting deliveries grows past this.
        'pending_delivery_warn_count' => 100,
    ],

    'worker' => [
        // How many executions one worker pass may process (stops a pass from
        // growing unboundedly while new requests keep arriving).
        'max_per_run' => 50,
        // Wall-clock budget of ONE pass, in seconds. A pass that spends it stops
        // claiming and leaves the rest scheduled for the next minute, so a long
        // queue (or one slow query) cannot make a pass outlive the 1-minute
        // schedule and pile up. Keep it below the schedule interval plus the
        // longest query you expect to finish within a pass.
        'pass_budget_seconds' => 50,
        // How many per-connection workers cli/worker_pool.php runs at the same
        // time. One child per active connection, started in waves of this size;
        // raise it only if you have many connections and the target DBs can take
        // the extra sessions.
        'pool_max_children' => 8,
        // A delayed 'running' execution older than this is treated as a crashed
        // worker's leftover and is failed by the next pass. Must comfortably
        // exceed the LONGEST request timeout the admin UI allows (delayed is
        // capped at 1800s = 30 min, so 90 min leaves 3x headroom).
        'stale_running_minutes' => 90,
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
        // ---- SSRF policy for receiver URLs (see Services\WebhookUrl). ----
        // Any admin can type a URL here, and the gateway then POSTs to it from
        // inside the network — so addresses that are never a legitimate
        // receiver (loopback unless allowed, link-local, cloud metadata, other
        // reserved ranges) are refused both on save and before each send.
        'allow_http' => (string)\Core\Env::get('GATEWAY_WEBHOOK_ALLOW_HTTP', '0') === '1',
        'allow_loopback' => (string)\Core\Env::get('GATEWAY_WEBHOOK_ALLOW_LOOPBACK', '0') === '1',
        // TRUE by default for this deployment: the receivers are expected to
        // live on the LAN. Set GATEWAY_WEBHOOK_ALLOW_PRIVATE_HOSTS=0 to allow
        // public receivers only (RFC1918/ULA addresses then refused too).
        // 169.254.169.254 and the other reserved ranges stay blocked either way.
        'allow_private_hosts' => (string)\Core\Env::get('GATEWAY_WEBHOOK_ALLOW_PRIVATE_HOSTS', '1') === '1',
        // Comma-separated exact host names (lowercase, no wildcards). Empty =
        // any host that passes the checks above is accepted.
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', (string)\Core\Env::get('GATEWAY_WEBHOOK_ALLOWED_HOSTS', ''))
        ), static fn (string $h): bool => $h !== '')),
    ],

    // Optional: connections used by tests/driver_test.php (see that file).
    'test_connections' => [],

    'connections' => [
        // SQL Server encryption: true = accept self-signed certificates (typical
        // intranet setups), false = enforce certificate validation.
        'sqlsrv_trust_server_certificate' => true,
    ],
];
