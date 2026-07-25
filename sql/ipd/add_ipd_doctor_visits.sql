-- =============================================================================
-- IPD (In-Door) — Phase 2: Consultant ward-round note.
--
-- Adds the immutable ward-round note table (ipd_doctor_visits) and the shared
-- care-events flow-sheet (ipd_care_events, first written to here for
-- DOCTOR_VISIT rows, extended by Phase 3 nursing). Depends on Phase 1
-- (ipd_admissions, ipd_ward_rates). Idempotent — safe to re-run.
--
-- Run in phpMyAdmin against the hims database AFTER add_ipd_admissions.sql.
-- All timestamps are PKT (+05:00).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipd_doctor_visits — one immutable ward-round note per save.
--    Notes are never UPDATEd after insert (enforced in app code); a correction
--    is a NEW note, so the diagnosis evolution is preserved with author + time.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_doctor_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    doctor_id INT NOT NULL,                  -- author = the logged-in covering consultant
    visited_at DATETIME NOT NULL,
    hospital_day INT NOT NULL,

    primary_diagnosis   VARCHAR(500) NOT NULL,
    secondary_diagnosis VARCHAR(500) NULL,
    active_complaints   VARCHAR(500) NULL,

    progress ENUM('IMPROVING','STABLE','SLOW','DETERIORATING','CRITICAL') NOT NULL,

    positive_findings    TEXT NULL,
    investigation_review TEXT NULL,
    clinical_assessment  TEXT NULL,
    management_plan      TEXT NULL,
    family_counselling   TEXT NULL,
    next_review ENUM('EVENING','TOMORROW_AM','TOMORROW_PM','AFTER_48H','PRN') NULL,

    is_paid TINYINT NOT NULL DEFAULT 0,      -- first note of the calendar day -> paid
    visit_charge DECIMAL(10,2) NOT NULL DEFAULT 0,  -- snapshot of the ward's consultant_visit_fee
    entered_by_user_id INT NOT NULL,         -- operator (may differ from author)

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ipd_dv_admission (admission_id, visited_at),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (entered_by_user_id) REFERENCES users(id)
);

-- -----------------------------------------------------------------------------
-- 2. ipd_care_events — the multi-day flow sheet / event stream.
--    Written here for DOCTOR_VISIT rows; Phase 3 adds NURSING_CARE / MEDICATION
--    / OBSERVATION / HANDOVER. ref_table/ref_id point back to the source row.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_care_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    event_type ENUM('DOCTOR_VISIT','NURSING_CARE','MEDICATION','OBSERVATION','HANDOVER','OTHER') NOT NULL,
    ref_table VARCHAR(40) NULL,
    ref_id INT NULL,
    note VARCHAR(1000) NULL,
    logged_by_id INT NOT NULL,
    logged_by_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL,
    event_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ipd_care_admission (admission_id, event_at),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (logged_by_id) REFERENCES users(id)
);

-- -----------------------------------------------------------------------------
-- 3. Permissions.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'IPD_WRITE_WARD_ROUND' AS `key`, 'Write an IPD consultant ward-round note' AS label, 'admin' AS category
    UNION ALL SELECT 'IPD_VIEW_WARD_ROUNDS', 'View IPD ward-round notes', 'admin'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Write: admin + doctor.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` = 'IPD_WRITE_WARD_ROUND'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- View: admin + doctor + staff (nurses/reception read the timeline).
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'DOCTOR' UNION ALL SELECT 'STAFF') r
JOIN permissions p ON p.`key` = 'IPD_VIEW_WARD_ROUNDS'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- =============================================================================
-- End IPD Phase 2 migration.
-- =============================================================================
