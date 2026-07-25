-- =============================================================================
-- Admin late-close of a stranded shift (2026-07-25).
--
-- A receptionist may close any of their OWN unclosed business days within the
-- last 5 days. Beyond that window the day is stranded, so an admin/manager
-- closes it ON THE RECEPTIONIST'S BEHALF using the receptionist's system-
-- computed tally. The closing row still belongs to the receptionist
-- (cashier_id) so the tally attribution is unchanged; closed_by_admin_id simply
-- records who actually pressed the button, for the slip's "closed by X on behalf
-- of Y" line and the audit trail.
--
-- Purely additive: existing self-closes leave closed_by_admin_id NULL.
--
-- Idempotent: information_schema-guarded ADD COLUMN. Run in phpMyAdmin against
-- the HMIS database. Safe to re-run. RUN BEFORE deploying the close-on-behalf code.
-- =============================================================================

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
