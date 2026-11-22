-- ============================================================================
--  007_hygiene.sql
--
--  Removes unused/dead schema from the PHP port: password-reset tokens,
--  cylinder-level assets and the inert phone_verified flag. Password resets
--  remain owner-assisted temporary passwords; phone verification still uses
--  phone_verifications for OTP codes only.
--
--  Apply with:  php database/migrate.php
--  Rollback:   007_hygiene_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS cylinder_assets;

ALTER TABLE users
  DROP COLUMN phone_verified;
