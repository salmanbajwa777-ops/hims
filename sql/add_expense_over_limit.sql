-- =============================================================================
-- Over-limit expense postings.
--
-- Previously a counter expense that exceeded the poster's per-category or overall
-- shift limit was HARD-BLOCKED — the receptionist could not record cash that had
-- genuinely gone out (e.g. a Rs 10,000 staff advance issued from a Rs 3,000/day
-- counter). Now such a posting is ALLOWED but flagged over-limit and forced
-- through the existing approve/reject flow (all non-admin postings already go
-- PENDING). These columns let approvers see it broke the limit and by how much.
--
--   over_limit  — 1 if the posting exceeded a shift limit at post time
--   limit_note  — human-readable snapshot of which limit(s) it broke, e.g.
--                 "Exceeds Advances limit Rs 3,000 (Rs 0 spent, over by Rs 7,000)"
--
-- Run in phpMyAdmin BEFORE deploying the code. Idempotent: safe to re-run.
-- =============================================================================

DROP PROCEDURE IF EXISTS hims_add_expense_over_limit_columns;
DELIMITER $$
CREATE PROCEDURE hims_add_expense_over_limit_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE()
                     AND table_name = 'expenses' AND column_name = 'over_limit') THEN
        ALTER TABLE expenses ADD COLUMN over_limit TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE()
                     AND table_name = 'expenses' AND column_name = 'limit_note') THEN
        ALTER TABLE expenses ADD COLUMN limit_note VARCHAR(255) NULL AFTER over_limit;
    END IF;
END$$
DELIMITER ;
CALL hims_add_expense_over_limit_columns();
DROP PROCEDURE IF EXISTS hims_add_expense_over_limit_columns;
