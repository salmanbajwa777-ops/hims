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
-- REWRITTEN 2026-07-27, flat. The original wrapped both ALTERs in a
-- DELIMITER-guarded CREATE PROCEDURE, which the Hostinger DB user is DENIED
-- (#1044 CREATE ROUTINE). It therefore never ran — a 2026-07-27 schema audit
-- found this the only unapplied migration in the whole sql/ directory, while
-- the code that reads these columns had already shipped. Flat ALTERs only.
--
-- MySQL has no "ADD COLUMN IF NOT EXISTS", so re-running errors with
-- #1060 "Duplicate column name". That error is harmless — it means the column
-- is already there. Run the statements one at a time if one has been applied.
--
-- Run in phpMyAdmin against the hims database.
-- =============================================================================

ALTER TABLE u402528120_hmis.expenses
    ADD COLUMN over_limit TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_status;

ALTER TABLE u402528120_hmis.expenses
    ADD COLUMN limit_note VARCHAR(255) NULL AFTER over_limit;

-- =============================================================================
-- Verify (fully qualified — querying information_schema switches phpMyAdmin's
-- current-database context, which is what makes a following bare table name
-- fail with "#1109 Unknown table ... in information_schema"):
--
--   SELECT column_name, column_type, column_default
--     FROM information_schema.columns
--    WHERE table_schema = 'u402528120_hmis'
--      AND table_name   = 'expenses'
--      AND column_name IN ('over_limit', 'limit_note');
--   -- expect 2 rows: tinyint(1) default 0, and varchar(255) nullable
-- =============================================================================
