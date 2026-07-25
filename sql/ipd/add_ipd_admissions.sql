-- =============================================================================
-- IPD (In-Door) — Phase 1: admit + room allotment + schema foundation.
--
-- A BRAND-NEW module, entirely separate from the ER short-stay admission_*
-- tables (which stay untouched). IPD gets its own ipd_* tables, its own
-- IPD_* permissions, and its own pages. This migration only lays the Phase 1
-- foundation: ward rates, the ipd_admissions lifecycle record, the IN_DOOR
-- visit disposition, and the Phase 1 permissions.
--
-- Idempotent, mirroring sql/add_admissions.sql: CREATE TABLE IF NOT EXISTS,
-- INSERT ... WHERE NOT EXISTS for seeds, and a stored-proc wrapper for the
-- guarded ALTER (shared hosting has no ADD ... IF NOT EXISTS). Run in
-- phpMyAdmin against the hims database. All timestamps are PKT (+05:00).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipd_ward_rates — admin-editable per-day bed/ward price + the daily
--    consultant visit fee. Stored, not hardcoded, so the clinic can retune.
--    Seeded so the admit modal's ward select isn't empty; admin fills real
--    rates on the IPD ward-rates screen.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_ward_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ward VARCHAR(100) NOT NULL UNIQUE,
    per_day_rate DECIMAL(10,2) NOT NULL DEFAULT 0,          -- bed/ward charge per day
    consultant_visit_fee DECIMAL(10,2) NOT NULL DEFAULT 0,  -- daily ward-round visit fee
    is_enabled TINYINT NOT NULL DEFAULT 1,
    updated_by_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Seed a few wards at rate 0 for admin to fill.
INSERT INTO ipd_ward_rates (ward, per_day_rate, consultant_visit_fee, is_enabled)
SELECT * FROM (
    SELECT 'General' AS ward, 0.00 AS per_day_rate, 0.00 AS consultant_visit_fee, 1 AS is_enabled
    UNION ALL SELECT 'Private', 0.00, 0.00, 1
    UNION ALL SELECT 'ICU',     0.00, 0.00, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM ipd_ward_rates r WHERE r.ward = seed.ward);

-- -----------------------------------------------------------------------------
-- 2. ipd_admissions — the core IPD lifecycle record.
--    Anchored to a visit exactly as the ER admissions table is (visit_id
--    UNIQUE -> visits.id -> patients.id). NO bed-resource table: the
--    receptionist simply types a room number (1-4).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL UNIQUE,

    ward VARCHAR(100) NOT NULL,
    room_no TINYINT NOT NULL,               -- typed 1-4, clamped in app code

    admitted_at DATETIME NOT NULL,          -- clock starts when the admit dialog is submitted

    -- Admitting consultant is REQUIRED at admit: auto-loaded when a doctor
    -- admits themselves, otherwise the receptionist picks. A system user id, or
    -- free text if the consultant is not a system user.
    admitting_consultant_id INT NULL,
    admitting_consultant_manual VARCHAR(255) NULL,

    provisional_diagnosis VARCHAR(500) NULL, -- the working diagnosis; seeds the first ward-round note

    admitted_by_id INT NOT NULL,
    admitted_by_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL,

    discharged_at DATETIME NULL,
    discharge_finalized_by_id INT NULL,
    discharge_finalized_at DATETIME NULL,

    status ENUM('ACTIVE','DISCHARGE_IN_PROGRESS','DISCHARGED') NOT NULL DEFAULT 'ACTIVE',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (admitting_consultant_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admitted_by_id) REFERENCES users(id),
    FOREIGN KEY (discharge_finalized_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ipd_status (status),
    INDEX idx_ipd_admitted_at (admitted_at)
);

-- -----------------------------------------------------------------------------
-- 3. Add IN_DOOR to visits.disposition so IPD stays never blur with the ER
--    SHORT_STAY count/queues. Guarded — read the current enum and only widen
--    it if IN_DOOR is missing. (MySQL on shared hosting has no clean
--    "ADD VALUE IF NOT EXISTS" for enums; we probe the column definition.)
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS hims_add_ipd_disposition;
DELIMITER $$
CREATE PROCEDURE hims_add_ipd_disposition()
BEGIN
    DECLARE col_type LONGTEXT;
    SELECT COLUMN_TYPE INTO col_type
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'disposition'
    LIMIT 1;

    IF col_type IS NOT NULL AND LOCATE('IN_DOOR', col_type) = 0 THEN
        -- Append IN_DOOR to whatever enum values already exist, preserving them.
        -- col_type looks like: enum('SHORT_STAY','SENT_HOME',...)
        SET @ddl = CONCAT(
            'ALTER TABLE visits MODIFY COLUMN disposition ',
            REPLACE(col_type, ')', ",'IN_DOOR')")
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;
CALL hims_add_ipd_disposition();
DROP PROCEDURE IF EXISTS hims_add_ipd_disposition;

-- -----------------------------------------------------------------------------
-- 4. Phase 1 permissions (IPD_* prefix, mirroring NURSING_*/RECEPTION_*).
-- -----------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'IPD_ADMIT_PATIENT'      AS `key`, 'Admit a patient to In-Door (IPD)'        AS label, 'admin'   AS category
    UNION ALL SELECT 'IPD_VIEW_WARD',        'View the In-Door ward list / patient stay',        'admin'
    UNION ALL SELECT 'IPD_MANAGE_WARD_RATES','Edit In-Door ward / bed rates',                    'admin'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Grant IPD_ADMIT_PATIENT to admin + staff (reception admits; doctor admits self).
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF' UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` = 'IPD_ADMIT_PATIENT'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- Grant IPD_VIEW_WARD to admin + staff + doctor (doctors must reach a patient to write a note).
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF' UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` = 'IPD_VIEW_WARD'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- Grant IPD_MANAGE_WARD_RATES to admin only.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role) r
JOIN permissions p ON p.`key` = 'IPD_MANAGE_WARD_RATES'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- =============================================================================
-- End IPD Phase 1 migration.
-- =============================================================================
