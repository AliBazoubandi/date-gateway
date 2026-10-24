-- ============================================================================
-- Migration: 2026-10-24-query-parameters.sql
-- Adds parameter metadata and execution scoping for parameterized queries.
-- Target: MySQL 5.7+ / MariaDB 10.4+
-- ============================================================================

SET NAMES utf8mb4;

-- 1. requests.params_json: JSON definition array of parameter schemas
ALTER TABLE `requests`
  ADD COLUMN `params_json` MEDIUMTEXT NULL AFTER `sql_text`;

-- 2. executions.params_json and params_hash: runtime parameters and hash for caching/sharing
ALTER TABLE `executions`
  ADD COLUMN `params_json` MEDIUMTEXT NULL AFTER `error_code`,
  ADD COLUMN `params_hash` CHAR(64) NULL DEFAULT NULL AFTER `params_json`,
  ADD KEY `idx_exec_params` (`request_id`, `params_hash`, `status`);

-- 3. calls.params_json: audit copy of parameters submitted by the client
ALTER TABLE `calls`
  ADD COLUMN `params_json` MEDIUMTEXT NULL AFTER `was_shared`;
