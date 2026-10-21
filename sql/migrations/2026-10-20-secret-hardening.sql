-- ============================================================================
-- Data Gateway - metadata DB migration: 2026-10-20 secret hardening
--
-- Two changes, both re-runnable (each runs only when information_schema says
-- its object is missing, so a second run is a no-op):
--
--   1. clients.webhook_secret -> clients.webhook_secret_enc.
--      The webhook HMAC secret becomes an encrypted-at-rest column. The data
--      itself is preserved by the rename; re-encrypting the existing values
--      is a separate one-off step because SQL cannot run the PHP cipher:
--        php cli\encrypt_webhook_secrets.php
--      (until it runs, the dispatcher still signs with the legacy plaintext
--      values and upgrades them lazily - deliveries never break).
--
--   2. clients.api_key_hash_hmac.
--      Peppered API-key lookup hash: HMAC-SHA256 of the key keyed with
--      security.master_key (GATEWAY_MASTER_KEY in config\.env). New and
--      rotated keys write it immediately; rows that only carry the legacy
--      bare SHA-256 (api_key_hash) keep authenticating through it and are
--      upgraded to the peppered hash on their first successful API call, so
--      NO existing key is invalidated.
--
-- Apply:
--   C:\xampp\mysql\bin\mysql.exe -u root -p data_gateway < sql\migrations\2026-10-20-secret-hardening.sql
--   php cli\encrypt_webhook_secrets.php
--
-- Verify:
--   php cli\check.php
--   -> clients.webhook_secret_enc present + every stored secret encrypted,
--      clients.api_key_hash_hmac present (+ how many rows still upgrade).
--
-- Deploy order matters: apply this BEFORE deploying the new code (the code
-- reads the new column names), then run the encrypt script, in any order
-- after that - the encrypt script and the dispatcher's lazy upgrade are
-- idempotent.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Rename webhook_secret -> webhook_secret_enc (values preserved; widen the
--    column: a 128-char secret becomes a ~220-char gcm:v1: blob).
-- ---------------------------------------------------------------------------
SET @has_old := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'clients'
                   AND COLUMN_NAME = 'webhook_secret');
SET @has_new := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'clients'
                   AND COLUMN_NAME = 'webhook_secret_enc');
SET @ddl := IF(@has_old > 0 AND @has_new = 0,
  'ALTER TABLE `clients` CHANGE COLUMN `webhook_secret` `webhook_secret_enc` VARCHAR(255) NULL DEFAULT NULL COMMENT ''AES-256-GCM blob (gcm:v1:...) of the webhook HMAC secret; keyed by security.master_key''',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Peppered API-key lookup hash. Nullable: legacy-only rows have NULL until
--    their first successful authentication upgrades them.
-- ---------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'clients'
                   AND COLUMN_NAME = 'api_key_hash_hmac');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `clients` ADD COLUMN `api_key_hash_hmac` CHAR(64) NULL DEFAULT NULL COMMENT ''HMAC-SHA256 of the API key, peppered with security.master_key (GATEWAY_MASTER_KEY); primary lookup, legacy bare SHA-256 stays in api_key_hash'' AFTER `api_key_hash`',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'clients'
                   AND INDEX_NAME = 'uq_clients_api_key_hmac');
SET @ddl := IF(@has_idx = 0,
  'ALTER TABLE `clients` ADD UNIQUE KEY `uq_clients_api_key_hmac` (`api_key_hash_hmac`)',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
