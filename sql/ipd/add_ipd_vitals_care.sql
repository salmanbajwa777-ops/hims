-- =============================================================================
-- IPD (In-Door) — Phase 3: nursing vitals + care flow-sheet + handovers.
-- Depends on Phase 1 (ipd_admissions). ipd_care_events is created in Phase 2;
-- if that hasn't run, it is created here too (idempotent). Run AFTER Phase 1.
-- All timestamps are PKT (+05:00).
-- =============================================================================

-- 1. ipd_vitals — column-for-column mirror of admission_vitals, FK ipd_admissions.
CREATE TABLE IF NOT EXISTS ipd_vitals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    recorded_at   DATETIME NOT NULL,
    temp_f        DECIMAL(4,1) NULL,
    pulse_bpm     INT NULL,
    resp_rate     INT NULL,
    systolic_bp   INT NULL,
    diastolic_bp  INT NULL,
    spo2_pct      INT NULL,
    blood_glucose DECIMAL(5,1) NULL,
    weight_kg     DECIMAL(5,1) NULL,
    height_cm     DECIMAL(5,1) NULL,
    ofc_cm        DECIMAL(5,1) NULL,
    pain_score    TINYINT NULL,
    notes         VARCHAR(500) NULL,
    recorded_by_id INT NOT NULL,
    recorded_by_role ENUM('NURSE','DOCTOR','ADMIN','MANAGER','RECEPTIONIST','STAFF') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ipd_vitals_admission (admission_id, recorded_at),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_id) REFERENCES users(id)
);

-- 2. ipd_care_events — also created in Phase 2. Idempotent create so Phase 3 is
--    safe to run standalone.
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

-- 3. ipd_handovers — nurse-to-nurse accountability over the multi-day stay.
CREATE TABLE IF NOT EXISTS ipd_handovers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    from_nurse_id INT NOT NULL,
    to_nurse_id INT NOT NULL,
    handover_time DATETIME NOT NULL,
    notes TEXT NULL,
    status_at_handover ENUM('ACTIVE','STABLE','CRITICAL') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ipd_handover_admission (admission_id),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (from_nurse_id) REFERENCES users(id),
    FOREIGN KEY (to_nurse_id) REFERENCES users(id)
);

-- 4. Permissions.
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'IPD_RECORD_VITALS'       AS `key`, 'Record vitals for an IPD patient'          AS label, 'admin' AS category
    UNION ALL SELECT 'IPD_LOG_CARE',           'Log IPD nursing care / flow-sheet events',           'admin'
    UNION ALL SELECT 'IPD_RECORD_HANDOVER',    'Record an IPD nurse handover',                       'admin'
    UNION ALL SELECT 'IPD_VIEW_VITALS_HISTORY','View IPD vitals / care history',                     'admin'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- IPD_RECORD_VITALS + IPD_VIEW_VITALS_HISTORY -> admin + staff + doctor.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF' UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` IN ('IPD_RECORD_VITALS','IPD_VIEW_VITALS_HISTORY')
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- IPD_LOG_CARE + IPD_RECORD_HANDOVER -> admin + staff.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF') r
JOIN permissions p ON p.`key` IN ('IPD_LOG_CARE','IPD_RECORD_HANDOVER')
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- =============================================================================
-- End IPD Phase 3 migration.
-- =============================================================================
