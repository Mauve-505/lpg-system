-- ============================================================================
--  002_customer_home_branch_down.sql
--
--  Development rollback only. It discards the customer home-branch links.
--  Restoring the backup taken by migrate.php is the supported rollback.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE customers
  DROP FOREIGN KEY fk_customers_branch,
  DROP INDEX idx_customers_branch,
  DROP COLUMN branch_id;

DELETE FROM schema_migrations WHERE version = '002_customer_home_branch';
