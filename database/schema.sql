-- ============================================================================
--  LPG Distributor - fresh-install schema (MySQL 8 / MariaDB 10.4+, InnoDB)
--  For an existing installation use database/migrate.php instead. This file
--  creates the database if needed and is safe to import.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS lpg_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lpg_db;

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Branches. created_by FK is added after users exists (circular reference).
-- ---------------------------------------------------------------------------
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

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- Optional home branch. Public signups start NULL and may order from any
  -- branch; staff-created customers belong to the creating branch.
  branch_id INT NULL,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(50) NULL,
  address VARCHAR(200) NULL,
  credit_limit DECIMAL(10,2) NULL,
  is_reseller TINYINT(1) NOT NULL DEFAULT 0,
  reseller_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_name (name),
  INDEX idx_customers_active (is_active),
  INDEX idx_customers_branch (branch_id),
  CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','staff','customer') NOT NULL DEFAULT 'staff',
  customer_id INT NULL,
  branch_id INT NULL,
  phone VARCHAR(40) NULL,
  phone_verified TINYINT(1) NOT NULL DEFAULT 0,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  session_version INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_branch (branch_id),
  INDEX idx_users_role_active (role, is_active),
  CONSTRAINT fk_users_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE branches
  ADD CONSTRAINT fk_branches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Security support tables
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Products / prices
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cylinder_sizes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(30) NOT NULL UNIQUE,
  selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  valve_type ENUM('snap_on','pol','both','unspecified') NOT NULL DEFAULT 'unspecified',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ---------------------------------------------------------------------------
-- Stock ledger. Stock for a branch/size is ALWAYS SUM(stock_movements).
-- full_change  : full cylinders on hand
-- empty_change : empty cylinders on hand
-- out_change   : cylinders out with customers
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  move_date DATE NOT NULL,
  size_id INT NOT NULL,
  ref_type ENUM('delivery','sale','adjustment','transfer','opening','void') NOT NULL,
  ref_id INT NOT NULL,
  full_change INT NOT NULL DEFAULT 0,
  empty_change INT NOT NULL DEFAULT 0,
  out_change INT NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_moves_branch_size_date (branch_id, size_id, move_date),
  INDEX idx_moves_ref (ref_type, ref_id),
  CONSTRAINT fk_moves_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_moves_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_moves_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One lock row per branch+size. Transactions SELECT ... FOR UPDATE this row
-- before reading/writing stock so concurrent sales cannot oversell.
CREATE TABLE IF NOT EXISTS stock_locks (
  branch_id INT NOT NULL,
  size_id INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, size_id),
  CONSTRAINT fk_lock_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_lock_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plant_deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  receipt_no VARCHAR(50) NOT NULL,
  supplier VARCHAR(120) NULL,
  remarks VARCHAR(200) NULL,
  received_by INT NOT NULL,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deliveries_branch_date (branch_id, delivery_date),
  CONSTRAINT fk_del_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_del_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_del_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plant_delivery_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT NOT NULL,
  size_id INT NOT NULL,
  full_received INT NOT NULL DEFAULT 0,
  empties_returned INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES plant_deliveries(id) ON DELETE CASCADE,
  CONSTRAINT fk_di_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  sale_no VARCHAR(30) NULL UNIQUE,
  sale_date DATE NOT NULL,
  customer_id INT NULL,
  payment_type ENUM('cash','credit') NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  order_id INT NULL,
  created_by INT NOT NULL,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sales_branch_date (branch_id, sale_date),
  INDEX idx_sales_customer (customer_id),
  CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  size_id INT NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  empties_received INT NOT NULL DEFAULT 0,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  payment_no VARCHAR(30) NULL UNIQUE,
  customer_id INT NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','gcash','maya','bank','other') NOT NULL DEFAULT 'cash',
  reference_no VARCHAR(80) NULL,
  notes VARCHAR(200) NULL,
  created_by INT NOT NULL,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payments_branch_date (branch_id, payment_date),
  INDEX idx_payments_customer (customer_id),
  CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  adj_date DATE NOT NULL,
  size_id INT NOT NULL,
  reason VARCHAR(50) NOT NULL,
  note VARCHAR(200) NULL,
  full_change INT NOT NULL DEFAULT 0,
  empty_change INT NOT NULL DEFAULT 0,
  created_by INT NOT NULL,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  voided_at DATETIME NULL,
  voided_by INT NULL,
  void_reason VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_adj_branch_date (branch_id, adj_date),
  CONSTRAINT fk_adj_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_adj_size FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_adj_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_adj_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Orders (header + items, multi-size)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  order_no VARCHAR(30) NULL UNIQUE,
  customer_id INT NOT NULL,
  payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  notes VARCHAR(200) NULL,
  status ENUM('pending','approved','delivered','cancelled') NOT NULL DEFAULT 'pending',
  order_date DATE NOT NULL,
  requested_date DATE NULL,
  time_slot VARCHAR(40) NULL,
  delivery_address VARCHAR(200) NULL,
  valve_type ENUM('snap_on','pol','na') NOT NULL DEFAULT 'na',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  sale_id INT NULL,
  rider_id INT NULL,
  proof_of_delivery VARCHAR(255) NULL,
  delivered_at DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- kept for backwards compatibility with the old API shape
  size_id INT NULL,
  qty INT NULL,
  INDEX idx_orders_branch_status (branch_id, status),
  INDEX idx_orders_customer (customer_id, created_at),
  CONSTRAINT fk_orders_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_rider FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ---------------------------------------------------------------------------
-- Inter-branch stock transfers
-- requested -> approved -> in_transit -> received
--                  \-> cancelled (from requested/approved)
-- Shipping and receiving each write the source/destination ledger exactly once.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Audit trail (never delete financial records; void + audit instead)
-- ---------------------------------------------------------------------------
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

-- Optional cylinder-level tracking (serial numbers / deposits).
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

-- ---------------------------------------------------------------------------
-- Default branch and starting catalogue.
-- Prices here are PLACEHOLDERS carried over from the old prototype and must be
-- replaced by the owner with real prices before launch.
-- ---------------------------------------------------------------------------
INSERT INTO branches (name, code, service_area_notes, is_active)
SELECT 'Main Branch', 'MAIN', 'Set the real service area in Branches.', 1
WHERE NOT EXISTS (SELECT 1 FROM branches);

INSERT INTO cylinder_sizes (label, selling_price) VALUES
  ('11kg', 950.00), ('22kg', 1850.00), ('50kg', 4100.00)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Required by database/migrate.php and to make a fresh install look migrated.
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(50) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_fresh_install');
-- Mark migrations already included in this fresh schema so migrate.php skips them.
INSERT IGNORE INTO schema_migrations (version) VALUES ('001_add_branches');
INSERT IGNORE INTO schema_migrations (version) VALUES ('002_customer_home_branch');

-- One lock row per branch+size so SELECT ... FOR UPDATE always finds a row.
INSERT IGNORE INTO stock_locks (branch_id, size_id)
SELECT b.id, z.id FROM branches b CROSS JOIN cylinder_sizes z;
