-- =============================================================================
-- IPD — mandatory nurse assignment at admit.
--
-- The ER short-stay table (admissions) already carries assigned_nurse_id /
-- assigned_at; IPD never had them — a nurse only appeared later via an
-- ipd_handovers row. Nurse assignment is now REQUIRED at the moment of admit on
-- BOTH routes, so ipd_admissions gains the same two columns.
--
-- Existing ACTIVE stays admitted before this change keep a NULL nurse: the
-- column stays NULL-able so this migration can never fail on live data, and the
-- "mandatory" part is enforced in the admit handler (config/ipd_actions.php),
-- which is the only writer that creates a row. Backfilling old stays is a
-- clinical decision, not a migration's job — ipd_admission.php lets a nurse be
-- assigned to any such stay.
--
-- No stored procedures (the DB user is denied CREATE ROUTINE), so each ALTER is
-- guarded by an information_schema check executed through a prepared statement.
-- Idempotent: safe to run more than once. All timestamps are PKT (+05:00).
-- Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipd_admissions.assigned_nurse_id — the nurse responsible for this stay,
--    captured at admit. Mirrors admissions.assigned_nurse_id exactly, including
--    ON DELETE SET NULL so deactivating a user never orphans a stay.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE ipd_admissions ADD COLUMN assigned_nurse_id INT NULL AFTER admitted_by_role',
        'SELECT ''ipd_admissions.assigned_nurse_id already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ipd_admissions'
      AND COLUMN_NAME  = 'assigned_nurse_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. ipd_admissions.assigned_at — when that nurse was put on the stay. Equal to
--    admitted_at for every row created after this change; it diverges only when
--    an older NULL-nurse stay is assigned retrospectively.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE ipd_admissions ADD COLUMN assigned_at DATETIME NULL AFTER assigned_nurse_id',
        'SELECT ''ipd_admissions.assigned_at already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ipd_admissions'
      AND COLUMN_NAME  = 'assigned_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3. Foreign key -> users(id). Added separately from the column so a re-run
--    after a partial apply still lands it.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE ipd_admissions ADD CONSTRAINT fk_ipd_adm_nurse FOREIGN KEY (assigned_nurse_id) REFERENCES users(id) ON DELETE SET NULL',
        'SELECT ''fk_ipd_adm_nurse already exists'''
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'ipd_admissions'
      AND CONSTRAINT_NAME = 'fk_ipd_adm_nurse'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. Index for "my patients" style lookups by nurse, mirroring
--    admissions.idx_admission_nurse.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE ipd_admissions ADD INDEX idx_ipd_admission_nurse (assigned_nurse_id, status)',
        'SELECT ''idx_ipd_admission_nurse already exists'''
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ipd_admissions'
      AND INDEX_NAME   = 'idx_ipd_admission_nurse'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Verify (expect 2 columns, and the FK + index present).
-- -----------------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'ipd_admissions'
  AND COLUMN_NAME IN ('assigned_nurse_id','assigned_at')
ORDER BY ORDINAL_POSITION;
