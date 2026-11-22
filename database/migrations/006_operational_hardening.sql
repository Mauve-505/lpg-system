-- ============================================================================
--  006_operational_hardening.sql
--
--  Adds the schema needed by the security/authorization hardening:
--  structured service areas for server-side outside-zone fees, idempotency for
--  sales and orders, and per-branch plant delivery receipt uniqueness.
--
--  Apply with:  php database/migrate.php
--  Rollback:   006_operational_hardening_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE branches
  ADD COLUMN service_area_cities TEXT NULL AFTER service_area_notes;

ALTER TABLE orders
  ADD COLUMN idempotency_key VARCHAR(80) NULL UNIQUE AFTER payment_verified_at;

ALTER TABLE sales
  ADD COLUMN idempotency_key VARCHAR(80) NULL UNIQUE AFTER payment_reference;

ALTER TABLE plant_deliveries
  ADD CONSTRAINT uq_deliveries_branch_receipt UNIQUE (branch_id, receipt_no);
