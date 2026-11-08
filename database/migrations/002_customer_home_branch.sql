-- ============================================================================
--  002_customer_home_branch.sql
--
--  Adds an optional home branch to customers so staff can be restricted to
--  customers that belong to, or have transacted with, their own branch.
--
--  Existing customers are backfilled from their earliest sale, order or
--  payment. Customers with no activity keep branch_id = NULL. That is correct
--  for public signups (they may order from any active branch) and lets the
--  owner assign a branch later with PUT /api/customers/{id} {"branch_id": N}.
--
--  Apply with:  php database/migrate.php
--  Rollback:   002_customer_home_branch_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE customers
  ADD COLUMN branch_id INT NULL AFTER id,
  ADD INDEX idx_customers_branch (branch_id),
  ADD CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

UPDATE customers c
SET c.branch_id = COALESCE(
  (SELECT s.branch_id FROM sales s WHERE s.customer_id = c.id ORDER BY s.id LIMIT 1),
  (SELECT o.branch_id FROM orders o WHERE o.customer_id = c.id ORDER BY o.id LIMIT 1),
  (SELECT p.branch_id FROM payments p WHERE p.customer_id = c.id ORDER BY p.id LIMIT 1)
);

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(50) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_customer_home_branch');
