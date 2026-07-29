-- =============================================================================
-- IPD — room categories, per-day nursing + MO charges, and the end of "ward".
--
-- WHY (two problems, one migration):
--
-- 1. THE BILL WAS MISSING CHARGES. add_ipd_admissions.sql seeded every rate row
--    at 0.00 and nobody set them, so live bills read "Private ward — 3 days
--    Rs 0". Worse, the bill had NO nursing charge and NO medical-officer charge
--    at all — neither concept existed anywhere in the schema. The final bill is
--    now room + nursing + MO (all per day) + consultant rounds + services.
--
-- 2. THERE ARE NO WARDS. The hospital has only private rooms. "ward" is renamed
--    out of the schema here; the UI copy and filenames go with it in the same
--    deploy.
--
-- WHAT THIS DOES NOT DO:
--   - It does NOT touch the four IPD_*_WARD_* permission KEYS. They are internal
--     identifiers read by has_permission() in 10 places; renaming them needs the
--     DB and all 10 call sites to land in the same instant or every IPD user
--     gets a 403. Only their human-readable LABELS are reworded (step 6).
--   - It does NOT rewrite history. Past bill lines keep the wording they were
--     issued with, and already-written daily-round notes keep their frozen
--     visit_charge — those notes are immutable by design, so setting rates now
--     fixes FUTURE rounds only.
--
-- RENAMES PRESERVE DATA. `RENAME COLUMN`/`RENAME TABLE` keep every row, so the
-- rates already entered live (Private @ 14000/2500) survive untouched.
--
-- No stored procedures — the DB user is denied CREATE ROUTINE (#1044) — so each
-- step is guarded by an information_schema check run through a prepared
-- statement. Idempotent: safe to run more than once.
--
-- Schema is named EXPLICITLY, never DATABASE(): phpMyAdmin often lands you in
-- information_schema, where DATABASE() resolves there and EVERY guard finds
-- nothing -- so each IF takes the "already done" branch and the migration
-- reports success while doing absolutely nothing.
--
-- All timestamps are PKT (+05:00). Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipd_ward_rates -> ipd_room_rates (table rename; all rows preserved).
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 1,
        'RENAME TABLE u402528120_hmis.ipd_ward_rates TO u402528120_hmis.ipd_room_rates',
        'SELECT ''ipd_room_rates already renamed (or source table absent)'''
    )
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_ward_rates'
      AND (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'ipd_room_rates') = 0
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. ipd_room_rates.ward -> room_category.
--    CHANGE (not RENAME COLUMN) so this works on MySQL 5.7 as well as 8.0; the
--    full column definition must be restated, UNIQUE included via the existing
--    index, which CHANGE preserves.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 1,
        'ALTER TABLE u402528120_hmis.ipd_room_rates CHANGE COLUMN ward room_category VARCHAR(100) NOT NULL',
        'SELECT ''ipd_room_rates.room_category already renamed'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_room_rates'
      AND COLUMN_NAME  = 'ward'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3. The two new per-day rates, mirroring the existing consultant_visit_fee.
--    Separately guarded so a re-run after a partial apply still lands each one.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.ipd_room_rates ADD COLUMN nursing_per_day_rate DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER per_day_rate',
        'SELECT ''nursing_per_day_rate already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_room_rates'
      AND COLUMN_NAME  = 'nursing_per_day_rate'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.ipd_room_rates ADD COLUMN mo_per_day_rate DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER nursing_per_day_rate',
        'SELECT ''mo_per_day_rate already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_room_rates'
      AND COLUMN_NAME  = 'mo_per_day_rate'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. ipd_admissions.ward -> room_category.
--    NOTE the rate is looked up by MATCHING THIS STRING against
--    ipd_room_rates.room_category — there is no FK. That is why renaming a
--    category in the admin screen must cascade to this column in the same
--    transaction (ipd_room_rates.php does that), or an in-flight patient's room
--    charge silently drops to Rs 0.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 1,
        'ALTER TABLE u402528120_hmis.ipd_admissions CHANGE COLUMN ward room_category VARCHAR(100) NOT NULL',
        'SELECT ''ipd_admissions.room_category already renamed'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_admissions'
      AND COLUMN_NAME  = 'ward'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5. Widen ipd_bill_items.item_kind for the two new charge lines.
--    STAY keeps its meaning (it IS the room line), so every historic row stays
--    valid and no data migration is needed. Guarded on the ENUM not already
--    containing NURSING.
--    WATCH OUT: ipd_discharge.php and ipd_invoice.php both sort with a hardcoded
--    FIELD(item_kind,...) which returns 0 for unlisted values — the new kinds
--    MUST be added there or they sort above everything else.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.ipd_bill_items MODIFY COLUMN item_kind ENUM(''STAY'',''NURSING'',''MO'',''CONSULT_VISIT'',''SERVICE'') NOT NULL DEFAULT ''SERVICE''',
        'SELECT ''item_kind already widened'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_bill_items'
      AND COLUMN_NAME  = 'item_kind'
      AND COLUMN_TYPE LIKE '%NURSING%'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 6. Reword the user-visible permission LABELS. The `key` values are deliberately
--    left alone (see the header note). permission_label() reads this column for
--    the 403 page, and permissions.php lists it on the admin screen — so this is
--    the only place "ward" was visible to a user in the RBAC UI.
-- -----------------------------------------------------------------------------
UPDATE permissions SET label = 'View the In-Door room list / patient stay' WHERE `key` = 'IPD_VIEW_WARD';
UPDATE permissions SET label = 'Edit In-Door room categories / rates'      WHERE `key` = 'IPD_MANAGE_WARD_RATES';
UPDATE permissions SET label = 'Write an IPD consultant daily-round note'  WHERE `key` = 'IPD_WRITE_WARD_ROUND';
UPDATE permissions SET label = 'View IPD daily-round notes'                WHERE `key` = 'IPD_VIEW_WARD_ROUNDS';

-- =============================================================================
-- End IPD room-charges migration.
-- =============================================================================

-- ---- Verify (read-only; run separately) ----
-- Every app table is FULLY QUALIFIED. Touching information_schema switches
-- phpMyAdmin's current-database context, so a bare `permissions` here resolves
-- to information_schema.permissions and fails with
--   #1109 Unknown table 'permissions' in information_schema
-- Expect: 3 rate columns, room_category on both tables, an item_kind ENUM
-- containing NURSING and MO, and 4 reworded labels with no "ward" in them.
--
-- SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = 'u402528120_hmis'
--   AND (
--        (TABLE_NAME = 'ipd_room_rates' AND COLUMN_NAME IN ('room_category','per_day_rate','nursing_per_day_rate','mo_per_day_rate','consultant_visit_fee'))
--     OR (TABLE_NAME = 'ipd_admissions' AND COLUMN_NAME = 'room_category')
--     OR (TABLE_NAME = 'ipd_bill_items' AND COLUMN_NAME = 'item_kind')
--   )
-- ORDER BY TABLE_NAME, ORDINAL_POSITION;
--
-- SELECT `key`, label FROM u402528120_hmis.permissions WHERE `key` LIKE 'IPD_%WARD%';
--
-- -- The live rates must have survived the rename:
-- SELECT room_category, per_day_rate, nursing_per_day_rate, mo_per_day_rate, consultant_visit_fee, is_enabled
-- FROM u402528120_hmis.ipd_room_rates ORDER BY room_category;
