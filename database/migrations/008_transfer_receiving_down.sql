-- ============================================================================
--  008_transfer_receiving_down.sql
--  Reverses the transfer receiving columns. Recorded receipt counts and
--  shortage notes are discarded.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE stock_transfer_items
  DROP COLUMN received_full,
  DROP COLUMN received_empty;

ALTER TABLE stock_transfers
  DROP COLUMN shortage_note;
