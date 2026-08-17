-- ============================================================================
-- Fuel accountability: who refuelled, and a per-vehicle jump limit. (2026-08-17)
--
-- WHY
--   Two separate problems, one migration because they land in the same feature.
--
--   1. WHO REFUELLED.  Litres bought is recorded; litres BURNED is inferred from
--      the odometer. When those two disagree persistently for one person on one
--      vehicle, fuel bought is not fuel burned. Nothing in the schema currently
--      records who took the vehicle to the pump, so the comparison cannot be
--      made at all. posted_by_id is NOT a substitute: reception posts nearly
--      every expense, so it is near-constant and carries no signal.
--
--   2. PER-VEHICLE JUMP LIMIT.  Distance is derived from consecutive odometer
--      readings, and a gap above VEH_MAX_PLAUSIBLE_GAP (5,000 km) is discarded
--      as a typo or a missed posting. One constant for the whole fleet is wrong
--      in both directions: 5,000 km between two fills is generous for an
--      ambulance and physically impossible for a 10-litre motorbike, so every
--      bike error between 600 and 5,000 km is currently invisible.
--
-- INCLUDES add_expense_tank_full.sql
--   That file may not have been run yet. Its ALTER is repeated as section 3
--   below so there is ONE file to run, not two. If it HAS already been applied,
--   section 3 errors with #1060 "Duplicate column name" — harmless, skip it and
--   carry on with section 4.
--
-- NO STORED PROCEDURE — the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- Flat statements, fully qualified. MySQL has no "ADD COLUMN IF NOT EXISTS", so
-- re-running a section errors with #1060, which means it already applied.
-- Run the sections one at a time if some are already in place.
--
-- Run in phpMyAdmin against the hims database.
-- ============================================================================


-- ---- 1. Who physically refuelled the vehicle --------------------------------
-- Nullable, and NOTHING is back-filled. A fill posted last month has no
-- knowable driver, and guessing one would put fiction straight into a report
-- whose entire purpose is to accuse someone. Historic rows read "Unassigned"
-- and are reported as such, separately from any named person.
--
-- ON DELETE SET NULL: a staff member who leaves is deactivated rather than
-- deleted, but if a user row ever is removed the expense must survive — the
-- money is real regardless of who is still employed.
ALTER TABLE `u402528120_hmis`.expenses
    ADD COLUMN refuelled_by_id INT NULL
        COMMENT 'users.id — who took the vehicle to the pump; NULL = not recorded'
        AFTER vehicle_id;

ALTER TABLE `u402528120_hmis`.expenses
    ADD CONSTRAINT fk_expenses_refuelled_by
        FOREIGN KEY (refuelled_by_id) REFERENCES `u402528120_hmis`.users(id)
        ON DELETE SET NULL;

-- The per-person report scans one vehicle's fuel rows grouped by person.
ALTER TABLE `u402528120_hmis`.expenses
    ADD INDEX idx_expense_refuelled_by (refuelled_by_id, vehicle_id, expense_date);


-- ---- 2. Per-vehicle plausibility limit --------------------------------------
-- NULL means "use the application default" (5,000 km) rather than "no limit".
-- Deliberately not defaulted to 5000 in the DB: a NULL lets the app raise its
-- own default later and have every un-tuned vehicle follow, whereas a stored
-- 5000 would freeze today's guess into every row forever.
ALTER TABLE `u402528120_hmis`.vehicles
    ADD COLUMN jump_limit_km INT NULL
        COMMENT 'Max believable km between two readings; NULL = app default (5000)'
        AFTER vehicle_type;

-- Tank size is not used by any calculation — it is a hint shown next to the
-- jump-limit field so whoever sets the limit has the right order of magnitude
-- in view (a 10 L bike cannot cover 3,000 km on one tank).
ALTER TABLE `u402528120_hmis`.vehicles
    ADD COLUMN tank_litres DECIMAL(6,2) NULL
        COMMENT 'Nominal tank capacity in litres; reference only, no maths uses it'
        AFTER jump_limit_km;

-- Sensible starting limits by type, applied ONLY where nothing is set yet.
-- These are still defaults, not facts — the vehicle form lets them be corrected.
UPDATE `u402528120_hmis`.vehicles
   SET jump_limit_km = 600
 WHERE jump_limit_km IS NULL AND vehicle_type LIKE '%Bike%';

UPDATE `u402528120_hmis`.vehicles
   SET jump_limit_km = 2000
 WHERE jump_limit_km IS NULL AND vehicle_type LIKE '%Ambulance%';


-- ---- 3. Full-tank flag  (from add_expense_tank_full.sql) --------------------
-- SKIP THIS SECTION if add_expense_tank_full.sql has already been run — it will
-- error #1060 "Duplicate column name: tank_full", which is harmless.
--
-- NULL = unknown / not a fuel row.  1 = filled to full.  0 = partial fill.
-- Nullable although MANDATORY for new Fuel rows (enforced in PHP): any default
-- here would be a lie about history, and unknown must stay unknown.
ALTER TABLE `u402528120_hmis`.expenses
    ADD COLUMN tank_full TINYINT(1) NULL
        COMMENT '1=filled to full, 0=partial, NULL=not a fuel row / unknown'
        AFTER litres;


-- ============================================================================
-- VERIFY (read-only). Fully qualified — querying information_schema switches
-- phpMyAdmin's current-database context, which is what makes a following bare
-- table name fail with "#1109 Unknown table ... in information_schema".
-- ============================================================================

-- Expect 2 rows: refuelled_by_id / int / YES, tank_full / tinyint(1) / YES.
SELECT column_name, column_type, is_nullable, column_default
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND column_name IN ('refuelled_by_id', 'tank_full');

-- Expect 2 rows: jump_limit_km / int / YES, tank_litres / decimal(6,2) / YES.
SELECT column_name, column_type, is_nullable, column_default
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'vehicles'
   AND column_name IN ('jump_limit_km', 'tank_litres');

-- Expect the FK and the index to be present.
SELECT constraint_name, column_name, referenced_table_name
  FROM information_schema.key_column_usage
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND constraint_name = 'fk_expenses_refuelled_by';

SELECT index_name, seq_in_index, column_name
  FROM information_schema.statistics
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND index_name = 'idx_expense_refuelled_by'
 ORDER BY seq_in_index;

-- Every vehicle and the limit it will now be measured against.
-- A NULL in the last column means the vehicle uses the app default of 5,000 km.
SELECT id, name, registration, vehicle_type, jump_limit_km, tank_litres
  FROM `u402528120_hmis`.vehicles
 ORDER BY is_active DESC, name;

-- Expect every existing expense to read NULL for both — nothing is back-filled.
SELECT COUNT(*) AS total_rows,
       SUM(refuelled_by_id IS NULL) AS refueller_unknown,
       SUM(tank_full IS NULL)       AS tank_unknown
  FROM `u402528120_hmis`.expenses;
