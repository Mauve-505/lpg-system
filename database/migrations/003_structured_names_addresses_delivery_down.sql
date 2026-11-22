-- ============================================================================
--  003_structured_names_addresses_delivery_down.sql
--
--  Development rollback only. It discards structured names, structured
--  addresses, delivery fees and the branch admin link. Restoring the backup
--  taken by migrate.php is the supported rollback.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE sales
  DROP COLUMN outside_zone_fee,
  DROP COLUMN is_outside_zone,
  DROP COLUMN rush_fee,
  DROP COLUMN is_rush;

ALTER TABLE orders
  DROP COLUMN outside_zone_fee,
  DROP COLUMN is_outside_zone,
  DROP COLUMN rush_fee,
  DROP COLUMN is_rush,
  DROP COLUMN delivery_longitude,
  DROP COLUMN delivery_latitude,
  DROP COLUMN delivery_postal_code,
  DROP COLUMN delivery_region,
  DROP COLUMN delivery_province,
  DROP COLUMN delivery_city,
  DROP COLUMN delivery_barangay,
  DROP COLUMN delivery_street;

ALTER TABLE customers
  DROP COLUMN longitude,
  DROP COLUMN latitude,
  DROP COLUMN postal_code,
  DROP COLUMN region,
  DROP COLUMN province,
  DROP COLUMN city,
  DROP COLUMN barangay,
  DROP COLUMN street,
  DROP COLUMN name_suffix,
  DROP COLUMN last_name,
  DROP COLUMN middle_name,
  DROP COLUMN first_name;

ALTER TABLE branches
  DROP FOREIGN KEY fk_branches_admin,
  DROP INDEX idx_branches_admin,
  DROP COLUMN branch_admin_id,
  DROP COLUMN outside_zone_fee,
  DROP COLUMN rush_fee,
  DROP COLUMN longitude,
  DROP COLUMN latitude,
  DROP COLUMN postal_code,
  DROP COLUMN region,
  DROP COLUMN province,
  DROP COLUMN city,
  DROP COLUMN barangay,
  DROP COLUMN street;

ALTER TABLE users
  DROP COLUMN name_suffix,
  DROP COLUMN last_name,
  DROP COLUMN middle_name,
  DROP COLUMN first_name;

UPDATE users SET role='staff' WHERE role IN ('branch_admin','delivery');

ALTER TABLE users
  MODIFY COLUMN role ENUM('owner','staff','customer') NOT NULL DEFAULT 'staff';

DELETE FROM schema_migrations WHERE version = '003_structured_names_addresses_delivery';
