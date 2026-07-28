-- =============================================================================
-- ER walk-in service bills — record the attending doctor.
--
-- er_bills was deliberately created "tied directly to a patient (no visit/
-- doctor)" because a walk-in injection/nebulization needs no consultation. In
-- practice every ER service is still given under a doctor's name, and a bill
-- that cannot say who ordered it is unauditable afterwards — so naming a doctor
-- is now MANDATORY when raising the bill (enforced in er_bill.php).
--
-- Mirrors the admissions column pair exactly: a system user id when the doctor
-- is registered, or free text when "Other" was picked for a visiting/locum
-- doctor. Readers COALESCE the two, as the admission slips already do.
--
-- Both columns are NULL-able so this migration can never fail on the existing
-- rows, which legitimately predate the requirement and stay blank. The
-- mandatory part is enforced in app code, the only writer.
--
-- No stored procedures (the DB user is denied CREATE ROUTINE), so each ALTER is
-- guarded by an information_schema check run through a prepared statement.
-- Idempotent: safe to run more than once. All timestamps are PKT (+05:00).
-- Schema is named EXPLICITLY, never DATABASE(): phpMyAdmin often lands you in
-- information_schema, where DATABASE() resolves there and EVERY guard finds
-- nothing -- so each IF takes the "already exists" branch and the migration
-- reports success while doing absolutely nothing.
-- Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. er_bills.doctor_id — the attending doctor when they are a system user.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.er_bills ADD COLUMN doctor_id INT NULL AFTER patient_id',
        'SELECT ''er_bills.doctor_id already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'er_bills'
      AND COLUMN_NAME  = 'doctor_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. er_bills.doctor_manual — free text for a doctor who is not a system user.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.er_bills ADD COLUMN doctor_manual VARCHAR(255) NULL AFTER doctor_id',
        'SELECT ''er_bills.doctor_manual already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'er_bills'
      AND COLUMN_NAME  = 'doctor_manual'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3. Foreign key -> users(id). ON DELETE SET NULL so removing a user never
--    orphans a money document.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.er_bills ADD CONSTRAINT fk_er_bill_doctor FOREIGN KEY (doctor_id) REFERENCES u402528120_hmis.users(id) ON DELETE SET NULL',
        'SELECT ''fk_er_bill_doctor already exists'''
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = 'u402528120_hmis'
      AND TABLE_NAME      = 'er_bills'
      AND CONSTRAINT_NAME = 'fk_er_bill_doctor'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. Index for per-doctor ER service reporting.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.er_bills ADD INDEX idx_er_bill_doctor (doctor_id)',
        'SELECT ''idx_er_bill_doctor already exists'''
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'er_bills'
      AND INDEX_NAME   = 'idx_er_bill_doctor'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Verify (expect both columns).
-- -----------------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'u402528120_hmis'
  AND TABLE_NAME   = 'er_bills'
  AND COLUMN_NAME IN ('doctor_id','doctor_manual')
ORDER BY ORDINAL_POSITION;
