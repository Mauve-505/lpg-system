-- ============================================================================
--  004_rush_same_day_fee_down.sql
--
--  Reverts the branch rush-fee default to 0. Existing fee amounts are left
--  untouched so an owner's configured ₱50/₱100 fee is not lost.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE branches
  MODIFY COLUMN rush_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00;
