-- ============================================================================
--  006_operational_hardening_down.sql
--  Reverses the operational hardening schema changes.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE plant_deliveries
  DROP INDEX uq_deliveries_branch_receipt;

ALTER TABLE sales
  DROP INDEX idempotency_key,
  DROP COLUMN idempotency_key;

ALTER TABLE orders
  DROP INDEX idempotency_key,
  DROP COLUMN idempotency_key;

ALTER TABLE branches
  DROP COLUMN service_area_cities;
