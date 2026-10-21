-- ============================================================================
-- Data Gateway - metadata DB migration: 2026-10-20 scripts mode
--
-- Adds connections.allow_writes (default 0 = unchanged, read-only only).
-- When an admin turns it on for a SQL Server connection, requests bound to it
-- may hold a multi-statement batch (DECLARE/#temp/INSERT/CREATE/DROP + a
-- final SELECT) that runs under the relaxed SqlGuard script policy: only
-- UPDATE/DELETE/MERGE/TRUNCATE, code invocation (EXEC/EXECUTE/CALL/
-- PREPARE/DEALLOCATE/HANDLER/DO) and server escape hatches (SHUTDOWN,
-- INSTALL/UNINSTALL, INTO OUTFILE/DUMPFILE) stay blocked. See README
-- section 12. Non-SQL Server connections cannot save the flag, and the
-- execution path refuses it even if a row is edited by hand.
--
-- Re-runnable by design: the ALTER only runs when information_schema says
-- the column is absent, so a second run is a no-op.
--
-- Apply BEFORE deploying the code that reads the column:
--   C:\xampp\mysql\bin\mysql.exe -u root -p data_gateway < sql\migrations\2026-10-20-scripts-mode.sql
--
-- Verify:
--   php cli\check.php
-- ============================================================================

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'connections'
                   AND COLUMN_NAME = 'allow_writes');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `connections` ADD COLUMN `allow_writes` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = scripts mode: multi-statement batches run under the relaxed SqlGuard policy (SQL Server connections only)'' AFTER `max_concurrency`',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
