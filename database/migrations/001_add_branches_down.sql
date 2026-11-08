-- ============================================================================
--  001_add_branches_down.sql - reverse the branch migration.
--
--  WARNING: this discards branch records and per-branch prices. It keeps the
--  original single-branch transaction rows (they are assigned back to the one
--  branch only conceptually; the branch_id columns are removed).
--
--  The supported, lossless rollback is to restore the backup taken before the
--  upgrade:
--    mysql -u root -p lpg_db < database/backups/lpg_db_before_branches.sql
--
--  Use this script only when you intentionally want to remove the feature.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE branches DROP FOREIGN KEY fk_branches_created_by;

ALTER TABLE orders
  DROP FOREIGN KEY fk_orders_rider,
  DROP FOREIGN KEY fk_orders_created_by,
  DROP FOREIGN KEY fk_orders_branch,
  DROP COLUMN branch_id, DROP COLUMN order_no, DROP COLUMN requested_date,
  DROP COLUMN time_slot, DROP COLUMN delivery_address, DROP COLUMN valve_type,
  DROP COLUMN subtotal, DROP COLUMN deposit_total, DROP COLUMN total_amount,
  DROP COLUMN rider_id, DROP COLUMN proof_of_delivery, DROP COLUMN delivered_at,
  DROP COLUMN created_by, DROP COLUMN created_at, DROP COLUMN updated_at;

ALTER TABLE stock_adjustments
  DROP FOREIGN KEY fk_adj_void_user,
  DROP FOREIGN KEY fk_adj_branch,
  DROP COLUMN branch_id, DROP COLUMN note, DROP COLUMN is_void, DROP COLUMN voided_at,
  DROP COLUMN voided_by, DROP COLUMN void_reason, DROP COLUMN created_at;

ALTER TABLE plant_deliveries
  DROP FOREIGN KEY fk_del_void_user,
  DROP FOREIGN KEY fk_del_branch,
  DROP COLUMN branch_id, DROP COLUMN supplier, DROP COLUMN is_void, DROP COLUMN voided_at,
  DROP COLUMN voided_by, DROP COLUMN void_reason, DROP COLUMN created_at;

ALTER TABLE payments
  DROP FOREIGN KEY fk_pay_void_user,
  DROP FOREIGN KEY fk_pay_branch,
  DROP COLUMN branch_id, DROP COLUMN payment_no, DROP COLUMN method, DROP COLUMN reference_no,
  DROP COLUMN notes, DROP COLUMN is_void, DROP COLUMN voided_at, DROP COLUMN voided_by,
  DROP COLUMN void_reason, DROP COLUMN created_at;

ALTER TABLE sale_items DROP COLUMN deposit_amount, DROP COLUMN line_total;

ALTER TABLE sales
  DROP FOREIGN KEY fk_sales_void_user,
  DROP FOREIGN KEY fk_sales_branch,
  DROP COLUMN branch_id, DROP COLUMN sale_no, DROP COLUMN subtotal, DROP COLUMN deposit_total,
  DROP COLUMN total_amount, DROP COLUMN order_id, DROP COLUMN is_void, DROP COLUMN voided_at,
  DROP COLUMN voided_by, DROP COLUMN void_reason, DROP COLUMN created_at;

ALTER TABLE stock_movements
  DROP FOREIGN KEY fk_moves_user,
  DROP FOREIGN KEY fk_moves_branch,
  DROP COLUMN branch_id, DROP COLUMN note, DROP COLUMN created_by, DROP COLUMN created_at,
  MODIFY ref_type ENUM('delivery','sale','adjustment') NOT NULL;

ALTER TABLE users
  DROP FOREIGN KEY fk_users_branch,
  DROP COLUMN branch_id, DROP COLUMN phone, DROP COLUMN phone_verified,
  DROP COLUMN must_change_password, DROP COLUMN session_version, DROP COLUMN last_login_at,
  DROP COLUMN updated_at;

DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS stock_transfer_items;
DROP TABLE IF EXISTS stock_transfers;
DROP TABLE IF EXISTS cylinder_assets;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS phone_verifications;
DROP TABLE IF EXISTS branch_prices;
DROP TABLE IF EXISTS stock_locks;
DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS branches;
