-- ============================================================================
--  005_prepaid_payments.sql
--
--  Removes credit (utang) from the operational model and introduces manually
--  verified GCash/Maya payments. COD remains the only "pay later" method.
--
--  The guard below refuses to run while any non-void credit sale still has an
--  outstanding balance. The owner must settle those balances before migrating.
--
--  Apply with:  php database/migrate.php
--  Rollback:   005_prepaid_payments_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

-- preflight-sql: SELECT COUNT(*) FROM (SELECT s.customer_id, s.branch_id, SUM(s.total_amount) charged, (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.customer_id=s.customer_id AND p.branch_id=s.branch_id AND p.is_void=0) paid FROM sales s WHERE s.is_void=0 AND s.payment_type='credit' GROUP BY s.customer_id, s.branch_id HAVING charged - paid > 0.005) outstanding
-- preflight-message: Outstanding credit balances exist. Settle them before running migration 005.

ALTER TABLE customers
  DROP COLUMN credit_limit,
  DROP COLUMN is_reseller,
  DROP COLUMN reseller_status;

ALTER TABLE branches
  ADD COLUMN gcash_number VARCHAR(80) NULL AFTER contact_phone,
  ADD COLUMN gcash_account_name VARCHAR(100) NULL AFTER gcash_number,
  ADD COLUMN maya_number VARCHAR(80) NULL AFTER gcash_account_name,
  ADD COLUMN maya_account_name VARCHAR(100) NULL AFTER maya_number;

ALTER TABLE cylinder_sizes
  ADD COLUMN deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER selling_price;

ALTER TABLE sales
  ADD COLUMN payment_method ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash' AFTER customer_id,
  ADD COLUMN payment_reference VARCHAR(80) NULL AFTER payment_method;

UPDATE sales SET payment_method='cash' WHERE payment_type='credit';
UPDATE sales SET payment_method='cash' WHERE payment_type='cash';

ALTER TABLE sales
  DROP COLUMN payment_type,
  ADD INDEX idx_sales_payment_ref (payment_method, payment_reference);

ALTER TABLE orders
  ADD COLUMN payment_method ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash' AFTER customer_id,
  ADD COLUMN payment_reference VARCHAR(80) NULL AFTER payment_method,
  ADD COLUMN payment_status ENUM('unpaid','pending_verification','verified','rejected') NOT NULL DEFAULT 'unpaid' AFTER payment_reference,
  ADD COLUMN payment_verified_by INT NULL AFTER payment_status,
  ADD COLUMN payment_verified_at DATETIME NULL AFTER payment_verified_by;

-- Historical orders are already settled; mark them verified so open legacy
-- orders remain deliverable under the new rule.
UPDATE orders SET payment_method='cash', payment_status='verified' WHERE payment_type='cash';
UPDATE orders SET payment_method='cash', payment_status='verified' WHERE payment_type='credit';

ALTER TABLE orders
  DROP COLUMN payment_type,
  ADD INDEX idx_orders_payment_ref (payment_method, payment_reference),
  ADD CONSTRAINT fk_orders_payment_verifier FOREIGN KEY (payment_verified_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS payment_references (
  method ENUM('gcash','maya') NOT NULL,
  reference_no VARCHAR(80) NOT NULL,
  order_id INT NULL,
  sale_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (method, reference_no),
  UNIQUE KEY uq_payment_ref_order (order_id),
  UNIQUE KEY uq_payment_ref_sale (sale_id),
  CONSTRAINT fk_payment_ref_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_ref_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The legacy payments table is intentionally retained for historical reads.
-- Its write endpoints are removed in the API.
