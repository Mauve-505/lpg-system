-- ============================================================================
--  005_prepaid_payments_down.sql
--
--  Restores the legacy credit columns. Historical GCash/Maya rows become
--  "credit" sales/orders because that is how the old schema represented every
--  non-cash transaction. The legacy payments table is left intact.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS payment_references;

ALTER TABLE orders
  DROP FOREIGN KEY fk_orders_payment_verifier,
  DROP INDEX idx_orders_payment_ref,
  ADD COLUMN payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER customer_id;

UPDATE orders SET payment_type='credit' WHERE payment_method IN ('gcash','maya');
UPDATE orders SET payment_type='cash' WHERE payment_method='cash';

ALTER TABLE orders
  DROP COLUMN payment_method,
  DROP COLUMN payment_reference,
  DROP COLUMN payment_status,
  DROP COLUMN payment_verified_by,
  DROP COLUMN payment_verified_at;

ALTER TABLE sales
  DROP INDEX idx_sales_payment_ref,
  ADD COLUMN payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER customer_id;

UPDATE sales SET payment_type='credit' WHERE payment_method IN ('gcash','maya');
UPDATE sales SET payment_type='cash' WHERE payment_method='cash';

ALTER TABLE sales
  DROP COLUMN payment_method,
  DROP COLUMN payment_reference;

ALTER TABLE cylinder_sizes
  DROP COLUMN deposit_amount;

ALTER TABLE branches
  DROP COLUMN gcash_number,
  DROP COLUMN gcash_account_name,
  DROP COLUMN maya_number,
  DROP COLUMN maya_account_name;

ALTER TABLE customers
  ADD COLUMN credit_limit DECIMAL(10,2) NULL AFTER longitude,
  ADD COLUMN is_reseller TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_limit,
  ADD COLUMN reseller_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER is_reseller;
