-- ============================================================================
--  004_rush_same_day_fee.sql
--
--  Rush orders are a same-day service. The standard flat add-on is ₱50; the
--  owner may change a branch's fee (for example to ₱100) in Branch settings.
--
--  Existing branches that still have a 0 fee are moved to the standard ₱50 so
--  the add-on is charged. An owner can set it back to 0 to offer rush free.
--
--  Apply with:  php database/migrate.php
--  Rollback:   004_rush_same_day_fee_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE branches
  MODIFY COLUMN rush_fee DECIMAL(10,2) NOT NULL DEFAULT 50.00;

UPDATE branches SET rush_fee = 50.00 WHERE rush_fee = 0.00;
