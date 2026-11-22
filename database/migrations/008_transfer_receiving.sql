-- ============================================================================
--  008_transfer_receiving.sql
--
--  Records actual received counts per transfer line so a destination can
--  accept fewer cylinders than were shipped (shortages), and stores an
--  optional shortage note on the transfer.
--
--  Apply with:  php database/migrate.php
--  Rollback:   008_transfer_receiving_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE stock_transfers
  ADD COLUMN shortage_note VARCHAR(200) NULL AFTER cancel_reason;

ALTER TABLE stock_transfer_items
  ADD COLUMN received_full INT NULL DEFAULT NULL AFTER qty_empty,
  ADD COLUMN received_empty INT NULL DEFAULT NULL AFTER received_full;
