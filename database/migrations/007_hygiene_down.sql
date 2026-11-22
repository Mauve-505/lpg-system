-- ============================================================================
--  007_hygiene_down.sql
--  Recreates the removed tables/column for rollback only. Data is not restorable.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER phone;

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
