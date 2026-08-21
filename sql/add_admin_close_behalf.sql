-- ============================================================================
-- Admin closes a shift on a receptionist's behalf — attribution column.
--                                                              (2026-08-21)
--
-- WHY THIS FILE EXISTS WHEN add_admin_late_close.sql ALREADY DID THIS
--   That file wraps the ALTER in CREATE PROCEDURE. The Hostinger DB user is
--   DENIED CREATE ROUTINE (#1044), so it errors out and the column is never
--   added. This is the same statement as a flat, fully-qualified ALTER.
--
-- WHAT IT IS FOR
--   shift_closings.cashier_id stays the RECEPTIONIST — the shift is theirs and
--   the money is attributed to them. closed_by_admin_id records who actually
--   performed the closing when an admin did it for them (off sick, left early,
--   could not finish). The A5 slip prints "CLOSED BY <admin> ON BEHALF OF
--   <receptionist>" whenever this is set.
--
--   Without it the feature still WORKS — the closing is recorded correctly and
--   the cash reconciles — but the slip cannot say who actually closed it, which
--   is the whole audit point. Every read is column_exists()-guarded, so running
--   this late breaks nothing; it just starts stamping new closings.
--
--   Nothing is back-filled. A closing already recorded has no knowable stand-in
--   admin, and inventing one would put a false name on a cash document.
--
-- NO STORED PROCEDURE. Re-running errors #1060 "Duplicate column name", which
-- is harmless and means it already applied.
--
-- Run in phpMyAdmin against the hims database.
-- ============================================================================

ALTER TABLE `u402528120_hmis`.shift_closings
    ADD COLUMN closed_by_admin_id INT NULL
        COMMENT 'users.id of the admin who closed this shift for the cashier; NULL = self-closed'
        AFTER cashier_id;

-- Separate statement: if the FK fails (an orphaned row, a differing engine) the
-- column is still in place and the feature works. The constraint is protective,
-- not load-bearing.
ALTER TABLE `u402528120_hmis`.shift_closings
    ADD CONSTRAINT fk_closings_closed_by_admin
        FOREIGN KEY (closed_by_admin_id)
        REFERENCES `u402528120_hmis`.users(id);


-- ============================================================================
-- VERIFY (read-only). Fully qualified — querying information_schema switches
-- phpMyAdmin's current-database context, which is what makes a following bare
-- table name fail with "#1109 Unknown table ... in information_schema".
-- ============================================================================

-- Expect one row: closed_by_admin_id / int / YES / NULL.
SELECT column_name, column_type, is_nullable, column_default
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis'
   AND table_name   = 'shift_closings'
   AND column_name  = 'closed_by_admin_id';

-- Expect the FK to be present.
SELECT constraint_name, column_name, referenced_table_name
  FROM information_schema.key_column_usage
 WHERE table_schema = 'u402528120_hmis'
   AND table_name   = 'shift_closings'
   AND constraint_name = 'fk_closings_closed_by_admin';

-- Expect every EXISTING closing to read NULL — nothing is back-filled.
SELECT COUNT(*) AS total_closings,
       SUM(closed_by_admin_id IS NULL)     AS self_closed,
       SUM(closed_by_admin_id IS NOT NULL) AS closed_on_behalf
  FROM `u402528120_hmis`.shift_closings;
