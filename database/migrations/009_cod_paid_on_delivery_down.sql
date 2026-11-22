-- ============================================================================
--  009_cod_paid_on_delivery_down.sql
--
--  Reverts the explicit COD `paid` status and removes the enum value. Orders
--  backfilled by 009 return to `unpaid`; GCash/Maya `verified` orders are not
--  touched.
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

UPDATE orders
SET payment_status='unpaid',
    payment_verified_by=NULL,
    payment_verified_at=NULL
WHERE payment_method='cash'
  AND status='delivered'
  AND payment_status='paid';

ALTER TABLE orders
  MODIFY payment_status ENUM('unpaid','pending_verification','verified','rejected') NOT NULL DEFAULT 'unpaid';
