-- ============================================================================
-- Data Gateway - production metadata-DB user
--
-- Run as an administrator (adjust the path if needed):
--   C:\xampp\mysql\bin\mysql.exe -u root < sql\create_user.sql
--
-- SECURITY: the password below is a PLACEHOLDER. Generate your own with
--   php -r "echo bin2hex(random_bytes(24));"
-- and set the same value as GATEWAY_DB_PASSWORD in config/.env.
-- Never commit the real password to this file.
-- ============================================================================

CREATE USER IF NOT EXISTS 'gateway'@'localhost' IDENTIFIED BY 'CHANGE-ME-strong-random-hex';
CREATE USER IF NOT EXISTS 'gateway'@'127.0.0.1' IDENTIFIED BY 'CHANGE-ME-strong-random-hex';

-- Exactly what the app needs on its own metadata schema - nothing more:
-- no CREATE/ALTER/DROP, no access to other schemas.
GRANT SELECT, INSERT, UPDATE, DELETE, LOCK TABLES ON data_gateway.* TO 'gateway'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, LOCK TABLES ON data_gateway.* TO 'gateway'@'127.0.0.1';

FLUSH PRIVILEGES;
