-- ============================================================================
--  009_cod_paid_on_delivery.sql
--
--  Cash on delivery is settled when the rider marks the order delivered. Add
--  an explicit `paid` payment status and backfill delivered cash orders that
--  were still recorded as unpaid by the earlier workflow.
--
--  Apply with:  php database/migrate.php
--  Rollback:   009_cod_paid_on_delivery_down.sql
-- ============================================================================

USE lpg_db;
SET NAMES utf8mb4;

ALTER TABLE orders
  MODIFY payment_status ENUM('unpaid','pending_verification','verified','rejected','paid') NOT NULL DEFAULT 'unpaid';

UPDATE orders
SET payment_status='paid',
    payment_verified_by=COALESCE(rider_id, created_by),
    payment_verified_at=COALESCE(delivered_at, NOW())
WHERE payment_method='cash'
  AND status='delivered'
  AND payment_status='unpaid';
