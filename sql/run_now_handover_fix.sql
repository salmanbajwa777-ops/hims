-- =============================================================================
-- RUN NOW — fixes the admin_handovers.php HTTP 500 (2026-07-25).
--
-- Commit cf3e3cf ("Attribute cash refunds to the payer's drawer + allow late
-- shift closing") shipped code that reads refunds.paid_out_by_id and writes
-- shift_closings.closed_by_admin_id, but the two migrations were never run.
-- The tally query in config/billing.php day_cash_tally() referenced a column
-- that does not exist, throwing an uncaught PDOException -> 500.
--
-- This file just runs both pending migrations together, in either order (they
-- touch different tables). Both are idempotent and safe to re-run.
--
-- Equivalent to running, separately:
--     sql/add_refund_paid_out_by.sql
--     sql/add_admin_late_close.sql
--
-- Run in phpMyAdmin against the HMIS database.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. refunds.paid_out_by_id — whose DRAWER the cash refund came out of.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS hims_add_refund_paid_out_by;
DELIMITER $$
CREATE PROCEDURE hims_add_refund_paid_out_by()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE()
                     AND table_name = 'refunds'
                     AND column_name = 'paid_out_by_id') THEN
        ALTER TABLE refunds
            ADD COLUMN paid_out_by_id INT NULL AFTER generated_by_id,
            ADD CONSTRAINT fk_refunds_paid_out_by FOREIGN KEY (paid_out_by_id) REFERENCES users(id),
            ADD INDEX idx_refund_paid_out (paid_out_by_id);
    END IF;
END $$
DELIMITER ;

CALL hims_add_refund_paid_out_by();
DROP PROCEDURE IF EXISTS hims_add_refund_paid_out_by;

-- Backfill: point historic CASH refunds at the original payer's drawer.
UPDATE refunds r
JOIN bills b ON b.id = r.bill_id
SET r.paid_out_by_id = b.paid_by_id
WHERE r.paid_out_by_id IS NULL
  AND r.refund_mode = 'cash'
  AND b.paid_by_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 2. shift_closings.closed_by_admin_id — who actually pressed "close on behalf".
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS hims_add_admin_late_close;
DELIMITER $$
CREATE PROCEDURE hims_add_admin_late_close()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE()
                     AND table_name = 'shift_closings'
                     AND column_name = 'closed_by_admin_id') THEN
        ALTER TABLE shift_closings
            ADD COLUMN closed_by_admin_id INT NULL AFTER cashier_id,
            ADD CONSTRAINT fk_closings_closed_by_admin FOREIGN KEY (closed_by_admin_id) REFERENCES users(id);
    END IF;
END $$
DELIMITER ;

CALL hims_add_admin_late_close();
DROP PROCEDURE IF EXISTS hims_add_admin_late_close;

-- ---------------------------------------------------------------------------
-- 3. Verify (read-only) — both should report the column present.
-- ---------------------------------------------------------------------------
SELECT 'refunds.paid_out_by_id'          AS column_checked,
       COUNT(*)                          AS present
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'refunds' AND column_name = 'paid_out_by_id'
UNION ALL
SELECT 'shift_closings.closed_by_admin_id',
       COUNT(*)
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'closed_by_admin_id';
