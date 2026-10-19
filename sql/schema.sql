-- ============================================================================
-- Data Gateway - metadata database schema (tables + foreign keys only).
-- Target: MySQL 5.7+ / MariaDB 10.4+ (XAMPP ships MariaDB).
-- The database itself is created by sql\create_database.sql; import this file
-- INTO that database, e.g.:
--   mysql -u root -p data_gateway < sql\schema.sql
-- (or paste into phpMyAdmin's SQL tab with data_gateway selected)
-- All timestamps are stored as DATETIME in Tehran local time (+03:30, no DST).
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
-- api_key_hash: SHA-256 hex of the API key (key itself shown once, never stored)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL,
  `type`           ENUM('agent','human') NOT NULL DEFAULT 'agent',
  `api_key_hash`   CHAR(64)     NOT NULL,
  `webhook_url`    VARCHAR(500) NULL DEFAULT NULL,
  `webhook_secret` VARCHAR(128) NULL DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NULL DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_name` (`name`),
  UNIQUE KEY `uq_clients_api_key_hash` (`api_key_hash`)
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
-- download_token_hash: SHA-256 hex of the download token (token itself never stored)
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
  `download_token_hash` CHAR(64)     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_exec_status_sched` (`status`, `scheduled_at`),
  KEY `idx_exec_request_status` (`request_id`, `status`),
  KEY `idx_exec_request_mode_fin` (`request_id`, `mode`, `status`, `finished_at`),
  KEY `idx_exec_token` (`download_token_hash`),
  KEY `idx_exec_expires` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Calls: one client asking. Several calls may attach to one execution.
-- id is the PUBLIC call_id given to clients (random 32 hex chars).
-- token_hash / token_enc: per-call download token. token_hash (SHA-256) is
-- used for lookup; token_enc (AES-256-GCM, master key) lets the webhook
-- rebuild a stable download link across retries. The plaintext is never
-- stored and is only returned to the owning client.
CREATE TABLE IF NOT EXISTS `calls` (
  `id`           CHAR(32)     NOT NULL,
  `client_id`    INT UNSIGNED NOT NULL,
  `request_id`   INT UNSIGNED NOT NULL,
  `execution_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `token_hash`   CHAR(64)     NULL DEFAULT NULL COMMENT 'per-call download token (rule-2 attaches to ready executions)',
  `token_enc`    TEXT         NULL DEFAULT NULL COMMENT 'AES-256-GCM copy of the per-call token; lets webhooks rebuild the link',
  `created_at`   DATETIME     NOT NULL,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `was_shared`   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_calls_token` (`token_hash`),
  KEY `idx_calls_client` (`client_id`, `created_at`),
  KEY `idx_calls_request` (`request_id`),
  KEY `idx_calls_execution` (`execution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Request logs (append-only by default; cleanup.retention.request_logs_days,
-- or the legacy cleanup.log_retention_days, prunes rows older than that window
-- in batches during each cleanup pass)
-- events: submitted|denied|shared|started|succeeded|failed|downloaded|expired|
--         webhook_sent|webhook_failed|instant_executed|instant_cache_hit|
--         rate_limited|timeout
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
-- Upgrading an EXISTING installation (CREATE TABLE IF NOT EXISTS above does
-- not touch tables that already exist). Run once:
--   ALTER TABLE `calls`
--     ADD COLUMN `token_enc` TEXT NULL DEFAULT NULL
--       COMMENT 'AES-256-GCM copy of the per-call token; lets webhooks rebuild the link'
--       AFTER `token_hash`;
-- Older rows keep token_enc = NULL: their calls simply get no link in the
-- webhook payload; everything else keeps working.
--
--   CREATE TABLE IF NOT EXISTS `client_rate_limits` (
--     `client_id`    INT UNSIGNED NOT NULL,
--     `window_start` DATETIME     NOT NULL,
--     `hits`         INT UNSIGNED NOT NULL DEFAULT 1,
--     PRIMARY KEY (`client_id`)
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--   ALTER TABLE `client_rate_limits`
--     ADD CONSTRAINT `fk_crl_client` FOREIGN KEY (`client_id`)
--       REFERENCES `clients` (`id`) ON DELETE CASCADE;
-- (the per-client submit throttle counter. Create it BEFORE deploying the new
-- code: with limits.client_submit_max > 0 every submit touches this table, so
-- a missing table would fail the submission endpoint.)
--
--   ALTER TABLE `executions`
--     ADD COLUMN `error_code` VARCHAR(32) NULL DEFAULT NULL AFTER `error_message`;
-- (the sanitized failure code clients see. Old failed rows keep NULL and are
-- reported as execution_failed; no backfill is needed.)
--
--   ALTER TABLE `admin_users`
--     ADD COLUMN `session_version` INT NOT NULL DEFAULT 0 AFTER `locked_until`;
-- (the admin session epoch. Run it BEFORE deploying the new code: every
-- authenticated admin page reads the column, so a missing one breaks the panel.
-- Existing sessions carry no version and are signed out on their next request,
-- which just means admins log in once more.)
-- ---------------------------------------------------------------------------
