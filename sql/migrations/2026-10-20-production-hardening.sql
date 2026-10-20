-- ============================================================================
-- Data Gateway - metadata DB migration: 2026-10-20 production hardening
--
-- Apply this to an EXISTING data_gateway database, ONCE per installation.
-- It is additive and re-runnable: every object is created or altered only
-- when information_schema says it is missing, so a second run is a no-op
-- (never "Duplicate column name" / "Duplicate key name" / "Duplicate foreign
-- key" - which is exactly why sql\schema.sql cannot be re-imported).
--
-- Apply:
--   C:\xampp\mysql\bin\mysql.exe -u root -p data_gateway < sql\migrations\2026-10-20-production-hardening.sql
--
-- Verify:
--   php cli\check.php
--   -> 13/13 tables, calls.token_enc, executions.error_code,
--      admin_users.session_version, calls.idempotency_key_hash, plus the
--      storage write probe and the worker heartbeat.
--
-- Do NOT import sql\schema.sql instead: it is the FRESH-INSTALL script. Its
-- trailing foreign-key ALTERs fail on a database that already has them
-- (ERROR 1005 ... Duplicate key), and the §8 upgrade block at its end would
-- never be reached.
--
-- What this adds:
--   1. client_rate_limits + fk_crl_client   per-client submit throttle
--   2. executions.error_code                sanitized failure code
--   3. admin_users.session_version          admin session epoch
--   4. calls.idempotency_key_hash + index   Idempotency-Key submits
--   5. worker_state                         GET /health worker heartbeat
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Per-client submit throttle counter.
-- The submit endpoint upserts one row per client while
-- limits.client_submit_max > 0, so create it BEFORE deploying the new code.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_rate_limits` (
  `client_id`     INT UNSIGNED NOT NULL,
  `window_start`  DATETIME     NOT NULL,
  `hits`          INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CREATE TABLE IF NOT EXISTS does nothing when the table already existed
-- (possibly without the constraint), so the foreign key gets its own check.
SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'client_rate_limits'
                  AND CONSTRAINT_NAME = 'fk_crl_client');
SET @ddl := IF(@has_fk = 0,
  'ALTER TABLE `client_rate_limits` ADD CONSTRAINT `fk_crl_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Sanitized failure code for the status API and webhook payloads.
-- Failure paths write it (Worker::markFailed, the instant failure updates and
-- the stale-instant cleanup), so a missing column means a failed run cannot be
-- recorded - the row can stay `running` and clients keep polling.
-- ---------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'executions'
                   AND COLUMN_NAME = 'error_code');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `executions` ADD COLUMN `error_code` VARCHAR(32) NULL DEFAULT NULL AFTER `error_message`',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Admin session epoch. Every authenticated admin page reads it, so create
-- it BEFORE deploying the new code or the panel breaks.
-- ---------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'admin_users'
                   AND COLUMN_NAME = 'session_version');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `admin_users` ADD COLUMN `session_version` INT NOT NULL DEFAULT 0 AFTER `locked_until`',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Idempotency-Key scope. EVERY call INSERT names this column (NULL when the
--     header is absent), so a missing column breaks all submits, keyed or not.
-- ---------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'calls'
                   AND COLUMN_NAME = 'idempotency_key_hash');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `calls` ADD COLUMN `idempotency_key_hash` CHAR(64) NULL DEFAULT NULL COMMENT ''SHA-256 of the submit Idempotency-Key header; scopes duplicate submits'' AFTER `token_enc`',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The replay lookup filters on all three columns; without the index a busy
-- gateway scans calls on every keyed submit.
SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'calls'
                   AND INDEX_NAME = 'idx_calls_idempotency');
SET @ddl := IF(@has_idx = 0,
  'ALTER TABLE `calls` ADD INDEX `idx_calls_idempotency` (`client_id`, `request_id`, `idempotency_key_hash`)',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Worker heartbeat: the single row GET /health reads. cli\worker_pool.php
-- writes the pass start before any work and the finish at the end, so create
-- it BEFORE deploying (a missing table only costs the health answer, but the
-- endpoint then always reports the worker as stale).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `worker_state` (
  `id`                    TINYINT UNSIGNED NOT NULL,
  `last_pass_started_at`  DATETIME     NOT NULL,
  `last_pass_finished_at` DATETIME     NOT NULL,
  `last_pass_failures`    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Not in this file on purpose: `executions.download_token_hash` was retired.
-- After the new code is deployed you may drop it (the previous worker version
-- still writes it, so dropping it earlier fails every finished run):
--   ALTER TABLE `executions` DROP COLUMN `download_token_hash`;
-- ---------------------------------------------------------------------------
