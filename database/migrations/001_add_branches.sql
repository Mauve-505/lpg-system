-- ============================================================================
--  001_add_branches.sql  -  upgrade the original single-branch database.
--
--  IMPORTANT - BACKUP FIRST.
--  Use database/migrate.php (recommended) which runs mysqldump automatically,
--  or run this first manually:
--
--    mysqldump -u root -p --single-transaction --routines lpg_db \
--      > database/backups/lpg_db_before_branches.sql
--
--  Apply:
--    mysql -u root -p lpg_db < database/migrations/001_add_branches.sql
--    php database/seed.php
--
--  Rollback: 001_add_branches_down.sql  (then restore the backup to keep the
--  branch history that the down script intentionally discards).
--
--  Existing rows are all assigned to Main Branch (code MAIN), so old totals
--  do not change.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

-- 1. Branches -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  address VARCHAR(200) NULL,
  contact_phone VARCHAR(40) NULL,
  service_area_notes VARCHAR(500) NULL,
  operating_hours VARCHAR(200) NULL,
  cutoff_time TIME NULL,
  max_order_qty INT NOT NULL DEFAULT 20,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_branches_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO branches (name, code, service_area_notes, is_active)
SELECT 'Main Branch', 'MAIN', 'Set the real service area in Branches.', 1
WHERE NOT EXISTS (SELECT 1 FROM branches);

SET @main_branch := (SELECT id FROM branches WHERE code = 'MAIN' LIMIT 1);

-- 2. Users: one branch for staff, NULL for owner/customer ---------------------
ALTER TABLE users
  ADD COLUMN branch_id INT NULL AFTER customer_id,
  ADD COLUMN phone VARCHAR(40) NULL AFTER branch_id,
  ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER phone,
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER phone_verified,
  ADD COLUMN session_version INT NOT NULL DEFAULT 1 AFTER must_change_password,
  ADD COLUMN last_login_at DATETIME NULL AFTER is_active,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE users SET branch_id = @main_branch WHERE role = 'staff';
UPDATE users SET must_change_password = 1 WHERE role = 'owner' AND username = 'admin';

ALTER TABLE users
  ADD INDEX idx_users_branch (branch_id),
  ADD INDEX idx_users_role_active (role, is_active),
  ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

ALTER TABLE branches
  ADD CONSTRAINT fk_branches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- 3. Add nullable branch_id to all transaction tables, backfill, then NOT NULL --
ALTER TABLE plant_deliveries ADD COLUMN branch_id INT NULL AFTER id;
UPDATE plant_deliveries SET branch_id = @main_branch WHERE branch_id IS NULL;
ALTER TABLE plant_deliveries
  MODIFY branch_id INT NOT NULL,
  ADD INDEX idx_deliveries_branch_date (branch_id, delivery_date),
  ADD CONSTRAINT fk_del_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

ALTER TABLE sales ADD COLUMN branch_id INT NULL AFTER id;
UPDATE sales SET branch_id = @main_branch WHERE branch_id IS NULL;
ALTER TABLE sales
  MODIFY branch_id INT NOT NULL,
  ADD INDEX idx_sales_branch_date (branch_id, sale_date),
  ADD INDEX idx_sales_customer (customer_id),
  ADD CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

ALTER TABLE payments ADD COLUMN branch_id INT NULL AFTER id;
UPDATE payments SET branch_id = @main_branch WHERE branch_id IS NULL;
ALTER TABLE payments
  MODIFY branch_id INT NOT NULL,
  ADD INDEX idx_payments_branch_date (branch_id, payment_date),
  ADD INDEX idx_payments_customer (customer_id),
  ADD CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

ALTER TABLE stock_adjustments ADD COLUMN branch_id INT NULL AFTER id;
UPDATE stock_adjustments SET branch_id = @main_branch WHERE branch_id IS NULL;
ALTER TABLE stock_adjustments
  MODIFY branch_id INT NOT NULL,
  ADD INDEX idx_adj_branch_date (branch_id, adj_date),
  ADD CONSTRAINT fk_adj_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

ALTER TABLE stock_movements ADD COLUMN branch_id INT NULL AFTER id;
UPDATE stock_movements SET branch_id = @main_branch WHERE branch_id IS NULL;
ALTER TABLE stock_movements
  MODIFY branch_id INT NOT NULL,
  MODIFY ref_type ENUM('delivery','sale','adjustment','transfer','opening','void') NOT NULL,
  ADD COLUMN note VARCHAR(200) NULL AFTER out_change,
  ADD COLUMN created_by INT NULL AFTER note,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by,
  ADD INDEX idx_moves_branch_size_date (branch_id, size_id, move_date),
  ADD CONSTRAINT fk_moves_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_moves_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE orders ADD COLUMN branch_id INT NULL AFTER id;
UPDATE orders SET branch_id = @main_branch WHERE branch_id IS NULL;
ALTER TABLE orders
  MODIFY branch_id INT NOT NULL,
  ADD INDEX idx_orders_branch_status (branch_id, status),
  ADD INDEX idx_orders_customer (customer_id, order_date),
  ADD CONSTRAINT fk_orders_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

-- 4. New supporting tables ----------------------------------------------------
CREATE TABLE IF NOT EXISTS branch_prices (
  branch_id INT NOT NULL,
  size_id INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, size_id),
  CONSTRAINT fk_bp_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_bp_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bp_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_locks (
  branch_id INT NOT NULL,
  size_id INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, size_id),
  CONSTRAINT fk_lock_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_lock_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  branch_id INT NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id VARCHAR(50) NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity (entity, entity_id),
  INDEX idx_audit_branch_time (branch_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  action ENUM('login','signup','password_reset') NOT NULL DEFAULT 'login',
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(80) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts_ip_time (ip, action, created_at),
  INDEX idx_attempts_user_time (username, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phone_verifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  phone VARCHAR(40) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  purpose ENUM('signup','phone_change','password_reset') NOT NULL DEFAULT 'signup',
  attempts TINYINT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_otp_phone (phone, purpose, expires_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_no VARCHAR(30) NOT NULL UNIQUE,
  from_branch_id INT NOT NULL,
  to_branch_id INT NOT NULL,
  status ENUM('requested','approved','in_transit','received','cancelled') NOT NULL DEFAULT 'requested',
  notes VARCHAR(200) NULL,
  idempotency_key VARCHAR(80) NULL UNIQUE,
  requested_by INT NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  shipped_by INT NULL,
  shipped_at DATETIME NULL,
  received_by INT NULL,
  received_at DATETIME NULL,
  cancelled_by INT NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(200) NULL,
  INDEX idx_transfers_from (from_branch_id, status),
  INDEX idx_transfers_to (to_branch_id, status),
  CONSTRAINT fk_tr_from FOREIGN KEY (from_branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tr_to FOREIGN KEY (to_branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tr_requested FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tr_approved FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tr_shipped FOREIGN KEY (shipped_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tr_received FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tr_cancelled FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  size_id INT NOT NULL,
  qty_full INT NOT NULL DEFAULT 0,
  qty_empty INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinder_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  size_id INT NOT NULL,
  serial_no VARCHAR(60) NOT NULL UNIQUE,
  status ENUM('in_stock','with_customer','maintenance','retired') NOT NULL DEFAULT 'in_stock',
  customer_id INT NULL,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_assets_branch_status (branch_id, status),
  CONSTRAINT fk_assets_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assets_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assets_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Order header extras + order_items backfill ---------------------------------
ALTER TABLE orders
  ADD COLUMN order_no VARCHAR(30) NULL UNIQUE AFTER branch_id,
  ADD COLUMN requested_date DATE NULL AFTER order_date,
  ADD COLUMN time_slot VARCHAR(40) NULL AFTER requested_date,
  ADD COLUMN delivery_address VARCHAR(200) NULL AFTER time_slot,
  ADD COLUMN valve_type ENUM('snap_on','pol','na') NOT NULL DEFAULT 'na' AFTER delivery_address,
  ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER valve_type,
  ADD COLUMN deposit_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_total,
  ADD COLUMN rider_id INT NULL AFTER sale_id,
  ADD COLUMN proof_of_delivery VARCHAR(255) NULL AFTER rider_id,
  ADD COLUMN delivered_at DATETIME NULL AFTER proof_of_delivery,
  ADD COLUMN created_by INT NULL AFTER delivered_at,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD CONSTRAINT fk_orders_rider FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_orders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  size_id INT NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO order_items (order_id, size_id, qty, unit_price, deposit_amount, line_total)
SELECT o.id, o.size_id, o.qty, COALESCE(z.selling_price, 0), 0, o.qty * COALESCE(z.selling_price, 0)
FROM orders o LEFT JOIN cylinder_sizes z ON z.id = o.size_id
WHERE o.size_id IS NOT NULL AND o.qty IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id);

UPDATE orders o
LEFT JOIN (
  SELECT order_id, SUM(line_total) subtotal FROM order_items GROUP BY order_id
) x ON x.order_id = o.id
SET o.subtotal = COALESCE(x.subtotal, 0),
    o.total_amount = COALESCE(x.subtotal, 0),
    o.order_no = CONCAT('ORD-', DATE_FORMAT(o.order_date, '%Y%m%d'), '-', LPAD(o.id, 5, '0'));

-- 6. Sale / payment / delivery / adjustment extras ------------------------------
ALTER TABLE sales
  ADD COLUMN sale_no VARCHAR(30) NULL UNIQUE AFTER branch_id,
  ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_type,
  ADD COLUMN deposit_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_total,
  ADD COLUMN order_id INT NULL AFTER total_amount,
  ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by,
  ADD COLUMN voided_at DATETIME NULL AFTER is_void,
  ADD COLUMN voided_by INT NULL AFTER voided_at,
  ADD COLUMN void_reason VARCHAR(200) NULL AFTER voided_by,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER void_reason,
  ADD CONSTRAINT fk_sales_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE sale_items
  ADD COLUMN deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER empties_received,
  ADD COLUMN line_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deposit_amount;

UPDATE sale_items SET line_total = qty * unit_price + deposit_amount;

UPDATE sales s
LEFT JOIN (SELECT sale_id, SUM(line_total) t FROM sale_items GROUP BY sale_id) x ON x.sale_id = s.id
SET s.subtotal = COALESCE(x.t, 0), s.total_amount = COALESCE(x.t, 0),
    s.sale_no = CONCAT('SALE-', DATE_FORMAT(s.sale_date, '%Y%m%d'), '-', LPAD(s.id, 5, '0'));

ALTER TABLE payments
  ADD COLUMN payment_no VARCHAR(30) NULL UNIQUE AFTER branch_id,
  ADD COLUMN method ENUM('cash','gcash','maya','bank','other') NOT NULL DEFAULT 'cash' AFTER amount,
  ADD COLUMN reference_no VARCHAR(80) NULL AFTER method,
  ADD COLUMN notes VARCHAR(200) NULL AFTER reference_no,
  ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by,
  ADD COLUMN voided_at DATETIME NULL AFTER is_void,
  ADD COLUMN voided_by INT NULL AFTER voided_at,
  ADD COLUMN void_reason VARCHAR(200) NULL AFTER voided_by,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER void_reason,
  ADD CONSTRAINT fk_pay_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE payments SET payment_no = CONCAT('PAY-', DATE_FORMAT(payment_date, '%Y%m%d'), '-', LPAD(id, 5, '0'));

ALTER TABLE plant_deliveries
  ADD COLUMN supplier VARCHAR(120) NULL AFTER receipt_no,
  ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER received_by,
  ADD COLUMN voided_at DATETIME NULL AFTER is_void,
  ADD COLUMN voided_by INT NULL AFTER voided_at,
  ADD COLUMN void_reason VARCHAR(200) NULL AFTER voided_by,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER void_reason,
  ADD CONSTRAINT fk_del_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE stock_adjustments
  ADD COLUMN note VARCHAR(200) NULL AFTER reason,
  ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by,
  ADD COLUMN voided_at DATETIME NULL AFTER is_void,
  ADD COLUMN voided_by INT NULL AFTER voided_at,
  ADD COLUMN void_reason VARCHAR(200) NULL AFTER voided_by,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER void_reason,
  ADD CONSTRAINT fk_adj_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL;

-- 7. Seed lock rows for every branch+size, so FOR UPDATE always finds a row ----
INSERT IGNORE INTO stock_locks (branch_id, size_id)
SELECT b.id, z.id FROM branches b CROSS JOIN cylinder_sizes z;

-- 8. Record the migration -----------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(50) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT IGNORE INTO schema_migrations (version) VALUES ('001_add_branches');
