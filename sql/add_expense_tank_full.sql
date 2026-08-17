-- ============================================================================
-- Full-tank flag on fuel postings.   (2026-08-13)
--
-- WHY
--   A per-fill km/L figure is only meaningful between two known-FULL tank
--   states. The build so far silently assumed every fill was full — usually
--   true, but nothing recorded it and nothing flagged the exceptions, so one
--   partial fill quietly produced a wrong efficiency figure that looked normal.
--
-- SEMANTICS
--   NULL = not applicable (Maintenance, Repairs, every pre-migration row, and
--          any non-vehicle expense). Same convention as the existing four
--          vehicle columns: absent rather than zero.
--   1    = tank filled to full
--   0    = partial fill
--
--   Nullable at the DB level even though it is MANDATORY for new Fuel rows —
--   the requirement is enforced in PHP at posting time. Making the column NOT
--   NULL would need a default, and any default here is a lie about history: an
--   old row's fill state is genuinely unknown, and recording it as 0 or 1 would
--   feed fiction into km/L. Unknown must stay unknown.
--
-- WHAT IT AFFECTS
--   ONLY the litres-based efficiency figure. A partial fill still contributes
--   its full spend to fuel cost/km and total cost/km, and its odometer reading
--   still counts toward distance — a partial fill is not a bad reading, the
--   odometer is still trustworthy. Money and distance are untouched.
--
-- NO STORED PROCEDURE — the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- Flat statement, fully qualified. Re-running errors with #1060 "Duplicate
-- column name", which is harmless and means it already applied.
--
-- Run in phpMyAdmin against the hims database.
-- ============================================================================

ALTER TABLE `u402528120_hmis`.expenses
    ADD COLUMN tank_full TINYINT(1) NULL
        COMMENT '1=filled to full, 0=partial, NULL=not a fuel row / unknown'
        AFTER litres;


-- ============================================================================
-- VERIFY (read-only). Fully qualified — querying information_schema switches
-- phpMyAdmin's current-database context, which is what makes a following bare
-- table name fail with "#1109 Unknown table ... in information_schema".
-- ============================================================================

-- Expect one row: tank_full / tinyint(1) / YES / NULL.
SELECT column_name, column_type, is_nullable, column_default
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis'
   AND table_name   = 'expenses'
   AND column_name  = 'tank_full';

-- Expect every existing row to read NULL — nothing is back-filled, because an
-- old fill's tank state is genuinely unknown.
SELECT COUNT(*) AS total_rows,
       SUM(tank_full IS NULL) AS unknown_rows,
       SUM(tank_full = 1)     AS full_rows,
       SUM(tank_full = 0)     AS partial_rows
  FROM `u402528120_hmis`.expenses;
