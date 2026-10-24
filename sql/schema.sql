-- ============================================================================
-- Data Gateway - metadata database schema (tables + foreign keys only).
-- Target: MySQL 5.7+ / MariaDB 10.4+ (XAMPP ships MariaDB).
-- The database itself is created by sql\create_database.sql; import this file
-- INTO that database, e.g.:
--   mysql -u root -p data_gateway < sql\schema.sql
-- (or paste into phpMyAdmin's SQL tab with data_gateway selected)
-- All timestamps are stored as DATETIME in Tehran local time (+03:30, no DST).
--
-- FRESH INSTALLS ONLY. Running this file on a database that already exists
-- fails: the foreign-key ALTERs at the end re-add constraints that are already
-- there (ERROR 1005 ... Duplicate key). Upgrade an existing installation with
--   sql\migrations\2026-10-20-production-hardening.sql
-- instead (see the pointer at the end of this file, and README section 8).
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Admin users (single role: every active admin sees everything)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64)  NOT NULL,
  `password_hash`   VARCHAR(255) NOT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at`   DATETIME     NULL DEFAULT NULL,
  `failed_attempts` INT          NOT NULL DEFAULT 0,
  `locked_until`    DATETIME     NULL DEFAULT NULL,
  -- Bumped whenever the password is replaced (cli\create_admin.php). The value
  -- lives in the admin session too, so an old cookie stops working on the next
  -- request instead of staying valid until its idle window ends.
  `session_version` INT          NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Login attempts by client IP (admin panel throttle): >=3 failures from one
-- address inside the 5-minute window are refused before the password check.
-- Rows are purged opportunistically once the window passes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `ip`              VARCHAR(45)  NOT NULL,
  `failed_count`    INT          NOT NULL DEFAULT 0,
  `window_start`    DATETIME     NOT NULL,
  `last_attempt_at` DATETIME     NOT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Target database connections (SQL Server / PostgreSQL / MySQL)
-- password_encrypted: AES-256-GCM blob "gcm:v1:<iv_b64>:<tag_b64>:<ct_b64>"
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `connections` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(100) NOT NULL,
  `db_type`            ENUM('sqlserver','pgsql','mysql') NOT NULL,
  `host`               VARCHAR(255) NOT NULL,
  `port`               INT UNSIGNED NULL DEFAULT NULL,
  `db_name`            VARCHAR(255) NOT NULL,
  `username`           VARCHAR(128) NOT NULL,
  `password_encrypted` TEXT         NOT NULL,
  `max_concurrency`    INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = use config default (2)',
  -- 1 = scripts mode: multi-statement batches (DECLARE/#temp/INSERT/CREATE/
  -- DROP + a final SELECT) run under the relaxed SqlGuard policy. SQL Server
  -- connections only; UPDATE/DELETE/EXEC-style keywords stay blocked.
  `allow_writes`       TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`         INT UNSIGNED NULL DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL,
  `updated_by`         INT UNSIGNED NULL DEFAULT NULL,
  `updated_at`         DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_connections_name` (`name`),
  KEY `idx_connections_type` (`db_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Requests (pre-approved, reviewed SQL exposed by code)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `requests` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                 VARCHAR(64)  NOT NULL,
  `name`                 VARCHAR(150) NOT NULL,
  `description`          TEXT         NULL,
  `sql_text`             MEDIUMTEXT   NOT NULL,
  `params_json`          MEDIUMTEXT   NULL COMMENT 'JSON array of parameter definitions: [{name, type, required, default, description}]',
  `connection_id`        INT UNSIGNED NOT NULL,
  `response_mode`        ENUM('delayed','instant') NOT NULL DEFAULT 'delayed',
  `instant_cache_seconds` INT         NOT NULL DEFAULT 0 COMMENT 'instant mode only; 0 = no cache',
  `delay_minutes`        INT          NOT NULL DEFAULT 0 COMMENT 'delayed mode only',
  `ttl_minutes`          INT          NOT NULL DEFAULT 15 COMMENT 'delayed mode only',
  `timeout_seconds`      INT          NOT NULL DEFAULT 60,
  `max_rows`             INT          NOT NULL DEFAULT 100000,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`           INT UNSIGNED NULL DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL,
  `updated_by`           INT UNSIGNED NULL DEFAULT NULL,
  `updated_at`           DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requests_code` (`code`),
  KEY `idx_requests_connection` (`connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Clients (API consumers: agents or humans)
-- api_key_hash: bare SHA-256 hex of the API key (key shown once, never stored).
--   Legacy form: kept so keys stored before the pepper still authenticate.
-- api_key_hash_hmac: HMAC-SHA256 of the API key, peppered (keyed) with
--   security.master_key — GATEWAY_MASTER_KEY in the untracked config/.env.
--   This is the primary lookup form; new and rotated keys always write it,
--   and a legacy-only row is upgraded on its first successful API call.
-- webhook_secret_enc: the webhook HMAC signing secret as an AES-256-GCM blob
--   (gcm:v1:...) under the same master key as connection passwords.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL,
  `type`           ENUM('agent','human') NOT NULL DEFAULT 'agent',
  `api_key_hash`   CHAR(64)     NOT NULL,
  `api_key_hash_hmac` CHAR(64)  NULL DEFAULT NULL,
  `webhook_url`    VARCHAR(500) NULL DEFAULT NULL,
  `webhook_secret_enc` VARCHAR(255) NULL DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_name` (`name`),
  UNIQUE KEY `uq_clients_api_key_hash` (`api_key_hash`),
  UNIQUE KEY `uq_clients_api_key_hmac` (`api_key_hash_hmac`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Permissions: which client may call which request
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_request_permissions` (
  `client_id`  INT UNSIGNED NOT NULL,
  `request_id` INT UNSIGNED NOT NULL,
  `granted_by` INT UNSIGNED NULL DEFAULT NULL,
  `granted_at` DATETIME     NOT NULL,
  PRIMARY KEY (`client_id`, `request_id`),
  KEY `idx_crp_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Executions: one actual query run (delayed or instant).
-- delayed:  scheduled -> running -> ready|failed -> expired
-- instant:  running -> ready|failed  (cache window in expires_at when enabled)
-- result_path: path relative to storage/ (e.g. "results/abc.json")
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `executions` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`          INT UNSIGNED NOT NULL,
  `mode`                ENUM('delayed','instant') NOT NULL DEFAULT 'delayed',
  `status`              ENUM('scheduled','running','ready','failed','expired') NOT NULL,
  `scheduled_at`        DATETIME NULL DEFAULT NULL,
  `started_at`          DATETIME NULL DEFAULT NULL,
  `finished_at`         DATETIME NULL DEFAULT NULL,
  `ready_at`            DATETIME NULL DEFAULT NULL,
  `expires_at`          DATETIME NULL DEFAULT NULL,
  `result_path`         VARCHAR(500) NULL DEFAULT NULL,
  `result_size_bytes`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `row_count`           INT UNSIGNED NULL DEFAULT NULL,
  `truncated`           TINYINT(1)   NOT NULL DEFAULT 0,
  `error_message`       TEXT         NULL,
  -- Public, sanitized failure code for the status API and webhook payloads
  -- (timeout / execution_failed / query_rejected / result_too_large). The raw
  -- driver text stays in error_message, which is admin-only.
  `error_code`          VARCHAR(32)  NULL DEFAULT NULL,
  `params_json`         MEDIUMTEXT   NULL,
  `params_hash`         CHAR(64)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_exec_status_sched` (`status`, `scheduled_at`),
  KEY `idx_exec_request_status` (`request_id`, `status`),
  KEY `idx_exec_request_mode_fin` (`request_id`, `mode`, `status`, `finished_at`),
  KEY `idx_exec_expires` (`status`, `expires_at`),
  KEY `idx_exec_params` (`request_id`, `params_hash`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Calls: one client asking. Several calls may attach to one execution.
-- id is the PUBLIC call_id given to clients (random 32 hex chars).
-- token_hash / token_enc: per-call download token. token_hash (SHA-256) is
-- used for lookup; token_enc (AES-256-GCM, master key) lets the webhook
-- rebuild a stable download link across retries. The plaintext is never
-- stored and is only returned to the owning client.
-- idempotency_key_hash: SHA-256 of the client's Idempotency-Key header, NULL
-- for the (normal) submission without one. The header value itself is never
-- stored. Not unique on purpose - see the note in README about concurrent
-- duplicate submits.
CREATE TABLE IF NOT EXISTS `calls` (
  `id`           CHAR(32)     NOT NULL,
  `client_id`    INT UNSIGNED NOT NULL,
  `request_id`   INT UNSIGNED NOT NULL,
  `execution_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `token_hash`   CHAR(64)     NULL DEFAULT NULL COMMENT 'per-call download token (rule-2 attaches to ready executions)',
  `token_enc`    TEXT         NULL DEFAULT NULL COMMENT 'AES-256-GCM copy of the per-call token; lets webhooks rebuild the link',
  `idempotency_key_hash` CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 of the submit Idempotency-Key header; scopes duplicate submits',
  `created_at`   DATETIME     NOT NULL,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `was_shared`   TINYINT(1)   NOT NULL DEFAULT 0,
  `params_json`  MEDIUMTEXT   NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_calls_token` (`token_hash`),
  KEY `idx_calls_client` (`client_id`, `created_at`),
  KEY `idx_calls_request` (`request_id`),
  KEY `idx_calls_execution` (`execution_id`),
  KEY `idx_calls_idempotency` (`client_id`, `request_id`, `idempotency_key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Request logs (append-only by default; cleanup.retention.request_logs_days,
-- or the legacy cleanup.log_retention_days, prunes rows older than that window
-- in batches during each cleanup pass)
-- events: submitted|denied|shared|started|succeeded|failed|downloaded|expired|
--         webhook_sent|webhook_failed|instant_executed|instant_cache_hit|
--         rate_limited|timeout|idempotent_replay
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `request_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timestamp`    DATETIME     NOT NULL,
  `client_id`    INT UNSIGNED NULL DEFAULT NULL,
  `request_id`   INT UNSIGNED NULL DEFAULT NULL,
  `call_id`      CHAR(32)     NULL DEFAULT NULL,
  `execution_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `event`        VARCHAR(32)  NOT NULL,
  `ip`           VARCHAR(45)  NULL DEFAULT NULL,
  `duration_ms`  INT UNSIGNED NULL DEFAULT NULL,
  `row_count`    INT UNSIGNED NULL DEFAULT NULL,
  `message`      TEXT         NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logs_ts` (`timestamp`),
  KEY `idx_logs_client_ts` (`client_id`, `timestamp`),
  KEY `idx_logs_request_ts` (`request_id`, `timestamp`),
  KEY `idx_logs_event_ts` (`event`, `timestamp`),
  KEY `idx_logs_call` (`call_id`),
  KEY `idx_logs_execution` (`execution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Admin audit log (append-only). before_json/after_json hold full snapshots;
-- the viewer derives and displays exactly the changed fields.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timestamp`     DATETIME     NOT NULL,
  `admin_user_id` INT UNSIGNED NULL DEFAULT NULL,
  `entity_type`   VARCHAR(32)  NOT NULL COMMENT 'request|connection|client|permission|admin',
  `entity_id`     INT UNSIGNED NULL DEFAULT NULL,
  `entity_name`   VARCHAR(200) NULL DEFAULT NULL,
  `action`        VARCHAR(16)  NOT NULL COMMENT 'create|update|delete|test_run|login|login_failed|logout|password_change',
  `before_json`   LONGTEXT     NULL,
  `after_json`    LONGTEXT     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_ts` (`timestamp`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_admin` (`admin_user_id`, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Webhook deliveries (at most one per call; up to 5 attempts with backoff)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `call_id`       CHAR(32)     NOT NULL,
  `attempt`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status_code`   INT          NULL DEFAULT NULL,
  `sent_at`       DATETIME     NULL DEFAULT NULL,
  `next_retry_at` DATETIME     NULL DEFAULT NULL,
  `state`         ENUM('pending','sent','failed','exhausted') NOT NULL DEFAULT 'pending',
  `last_error`    VARCHAR(500) NULL DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_whd_call` (`call_id`),
  KEY `idx_whd_state` (`state`, `next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Per-client submit throttle counters (one row per client, fixed window).
-- `hits` is incremented atomically by the submit endpoint (single
-- INSERT ... ON DUPLICATE KEY UPDATE), which is why this exists separately
-- from request_logs: counting log rows raced under concurrent submits and
-- changed behavior whenever logs were pruned. window_start resets the count
-- once the window (limits.client_submit_window_seconds) has passed.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_rate_limits` (
  `client_id`     INT UNSIGNED NOT NULL,
  `window_start`  DATETIME     NOT NULL,
  `hits`          INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Worker heartbeat: the row GET /health reads. cli\worker_pool.php writes it at
-- the end of every pass, including a pass that had failures, so the timestamp
-- answers "does the schedule still fire?" and last_pass_failures answers
-- "is what it finds healthy?". Nothing appends: the id is always 1 and the row
-- is only ever overwritten, so a heartbeat cannot grow into an unpruned log.
-- No row at all simply means "no pass has been recorded yet", which /health
-- reports as stale.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `worker_state` (
  `id`                    TINYINT UNSIGNED NOT NULL,
  `last_pass_started_at`  DATETIME     NOT NULL,
  `last_pass_finished_at` DATETIME     NOT NULL,
  `last_pass_failures`    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Foreign keys (added last so the import order never matters)
-- ---------------------------------------------------------------------------
ALTER TABLE `requests`
  ADD CONSTRAINT `fk_requests_connection`
  FOREIGN KEY (`connection_id`) REFERENCES `connections` (`id`) ON DELETE RESTRICT;

ALTER TABLE `executions`
  ADD CONSTRAINT `fk_exec_request`
  FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE RESTRICT;

ALTER TABLE `client_request_permissions`
  ADD CONSTRAINT `fk_crp_client`  FOREIGN KEY (`client_id`)  REFERENCES `clients` (`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_crp_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE;

ALTER TABLE `calls`
  ADD CONSTRAINT `fk_calls_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_calls_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_calls_execution` FOREIGN KEY (`execution_id`) REFERENCES `executions` (`id`) ON DELETE SET NULL;

ALTER TABLE `webhook_deliveries`
  ADD CONSTRAINT `fk_whd_call` FOREIGN KEY (`call_id`) REFERENCES `calls` (`id`) ON DELETE RESTRICT;

ALTER TABLE `client_rate_limits`
  ADD CONSTRAINT `fk_crl_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- Upgrading an EXISTING installation
--
-- Everything an older schema can be missing lives in ONE re-runnable file:
--
--   sql\migrations\2026-10-20-production-hardening.sql
--
-- It adds client_rate_limits (+ fk_crl_client), executions.error_code,
-- admin_users.session_version, calls.idempotency_key_hash (+ its index) and
-- worker_state, each only when information_schema says it is absent, so the
-- file can be applied again safely. Apply it BEFORE deploying the code and
-- then run `php cli\check.php` (13/13 tables + the four upgrade columns).
--
-- Secret hardening (webhook secret encrypted at rest + peppered API-key
-- hashes) is its own re-runnable file, applied the same way BEFORE deploying:
--
--   sql\migrations\2026-10-20-secret-hardening.sql
--
-- followed by the one-off re-encryption of any webhook secret that is still
-- stored as plaintext:
--   php cli\encrypt_webhook_secrets.php
--
-- Scripts mode (connections.allow_writes, see README section 12) is its own
-- re-runnable file, applied the same way BEFORE deploying the code that
-- reads the column:
--   sql\migrations\2026-10-20-scripts-mode.sql
--
-- A database created before the webhook feature also needs this one-off
-- ALTER, which the migration deliberately does not repeat (it is not part of
-- the 2026-10-20 hardening set):
--   ALTER TABLE `calls`
--     ADD COLUMN `token_enc` TEXT NULL DEFAULT NULL
--       COMMENT 'AES-256-GCM copy of the per-call token; lets webhooks rebuild the link'
--       AFTER `token_hash`;
--
-- Retired, drop only AFTER the new code is deployed (the previous worker
-- version still writes it):
--   ALTER TABLE `executions` DROP COLUMN `download_token_hash`;
-- ---------------------------------------------------------------------------
