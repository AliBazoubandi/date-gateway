-- ============================================================================
-- Data Gateway - metadata database creation.
-- Run FIRST, with an administrative user, e.g.:
--   mysql -u root -p < sql\create_database.sql
-- then load the tables into it:
--   mysql -u root -p data_gateway < sql\schema.sql
-- (or paste both into phpMyAdmin's SQL tab, in this order)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `data_gateway`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
