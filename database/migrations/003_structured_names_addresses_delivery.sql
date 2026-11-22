-- ============================================================================
--  003_structured_names_addresses_delivery.sql
--
--  Adds:
--    * structured person names (first / middle / last / suffix) on customers
--      and users, keeping the composed `name` column for display and older
--      clients;
--    * structured addresses (street / barangay / city / province / region /
--      postal code / coordinates) on branches, customers and order delivery;
--    * rush-order and outside-delivery-zone flags and fees on orders and sales;
--    * branch-configurable fee amounts;
--    * the `branch_admin` and `delivery` staff roles plus a branch admin link.
--
--  Apply with:  php database/migrate.php
--  Rollback:   003_structured_names_addresses_delivery_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Roles and branch admin
-- ---------------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('owner','branch_admin','staff','delivery','customer') NOT NULL DEFAULT 'staff';

ALTER TABLE users
  ADD COLUMN first_name VARCHAR(60) NULL AFTER name,
  ADD COLUMN middle_name VARCHAR(60) NULL AFTER first_name,
  ADD COLUMN last_name VARCHAR(60) NULL AFTER middle_name,
  ADD COLUMN name_suffix VARCHAR(20) NULL AFTER last_name;

ALTER TABLE branches
  ADD COLUMN street VARCHAR(160) NULL AFTER address,
  ADD COLUMN barangay VARCHAR(100) NULL AFTER street,
  ADD COLUMN city VARCHAR(100) NULL AFTER barangay,
  ADD COLUMN province VARCHAR(100) NULL AFTER city,
  ADD COLUMN region VARCHAR(100) NULL AFTER province,
  ADD COLUMN postal_code VARCHAR(20) NULL AFTER region,
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER postal_code,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN rush_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER max_order_qty,
  ADD COLUMN outside_zone_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER rush_fee,
  ADD COLUMN branch_admin_id INT NULL AFTER outside_zone_fee,
  ADD INDEX idx_branches_admin (branch_admin_id),
  ADD CONSTRAINT fk_branches_admin FOREIGN KEY (branch_admin_id) REFERENCES users(id) ON DELETE SET NULL;

-- Backfill structured names from the legacy display name where possible.
UPDATE users
SET last_name = TRIM(SUBSTRING_INDEX(name, ',', 1))
WHERE last_name IS NULL AND name LIKE '%,%';

UPDATE users
SET first_name = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(name, ',', 2), ',', -1))
WHERE first_name IS NULL AND name LIKE '%,%';

UPDATE users
SET last_name = TRIM(SUBSTRING_INDEX(name, ' ', -1))
WHERE last_name IS NULL AND name NOT LIKE '%,%' AND name LIKE '% %';

UPDATE users
SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1))
WHERE first_name IS NULL AND name NOT LIKE '%,%' AND name LIKE '% %';

-- ---------------------------------------------------------------------------
-- Customers
-- ---------------------------------------------------------------------------
ALTER TABLE customers
  ADD COLUMN first_name VARCHAR(60) NULL AFTER name,
  ADD COLUMN middle_name VARCHAR(60) NULL AFTER first_name,
  ADD COLUMN last_name VARCHAR(60) NULL AFTER middle_name,
  ADD COLUMN name_suffix VARCHAR(20) NULL AFTER last_name,
  ADD COLUMN street VARCHAR(160) NULL AFTER address,
  ADD COLUMN barangay VARCHAR(100) NULL AFTER street,
  ADD COLUMN city VARCHAR(100) NULL AFTER barangay,
  ADD COLUMN province VARCHAR(100) NULL AFTER city,
  ADD COLUMN region VARCHAR(100) NULL AFTER province,
  ADD COLUMN postal_code VARCHAR(20) NULL AFTER region,
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER postal_code,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;

UPDATE customers
SET last_name = TRIM(SUBSTRING_INDEX(name, ',', 1))
WHERE last_name IS NULL AND name LIKE '%,%';

UPDATE customers
SET first_name = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(name, ',', 2), ',', -1))
WHERE first_name IS NULL AND name LIKE '%,%';

UPDATE customers
SET last_name = TRIM(SUBSTRING_INDEX(name, ' ', -1))
WHERE last_name IS NULL AND name NOT LIKE '%,%' AND name LIKE '% %';

UPDATE customers
SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1))
WHERE first_name IS NULL AND name NOT LIKE '%,%' AND name LIKE '% %';

-- ---------------------------------------------------------------------------
-- Orders and sales fees / delivery address
-- ---------------------------------------------------------------------------
ALTER TABLE orders
  ADD COLUMN delivery_street VARCHAR(160) NULL AFTER delivery_address,
  ADD COLUMN delivery_barangay VARCHAR(100) NULL AFTER delivery_street,
  ADD COLUMN delivery_city VARCHAR(100) NULL AFTER delivery_barangay,
  ADD COLUMN delivery_province VARCHAR(100) NULL AFTER delivery_city,
  ADD COLUMN delivery_region VARCHAR(100) NULL AFTER delivery_province,
  ADD COLUMN delivery_postal_code VARCHAR(20) NULL AFTER delivery_region,
  ADD COLUMN delivery_latitude DECIMAL(10,7) NULL AFTER delivery_postal_code,
  ADD COLUMN delivery_longitude DECIMAL(10,7) NULL AFTER delivery_latitude,
  ADD COLUMN is_rush TINYINT(1) NOT NULL DEFAULT 0 AFTER valve_type,
  ADD COLUMN rush_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER is_rush,
  ADD COLUMN is_outside_zone TINYINT(1) NOT NULL DEFAULT 0 AFTER rush_fee,
  ADD COLUMN outside_zone_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER is_outside_zone;

ALTER TABLE sales
  ADD COLUMN is_rush TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_total,
  ADD COLUMN rush_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER is_rush,
  ADD COLUMN is_outside_zone TINYINT(1) NOT NULL DEFAULT 0 AFTER rush_fee,
  ADD COLUMN outside_zone_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER is_outside_zone;

-- ---------------------------------------------------------------------------
-- Backfill order delivery fields from the legacy free-text address.
-- ---------------------------------------------------------------------------
UPDATE orders
SET delivery_street = delivery_address
WHERE delivery_street IS NULL AND delivery_address IS NOT NULL AND delivery_address <> '';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(50) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_structured_names_addresses_delivery');
