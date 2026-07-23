-- Phase 3 (billing/flow overhaul, 2026-07-24): ER stay VITALS capture.
--
-- Vitals are CLINICAL, not financial — this table is entirely off the billing
-- pipeline (unlike admission_services). A nurse records what she takes during the
-- stay; every measurement column is nullable. The doctor reads the timeline.
--
-- Permissions already exist and are seeded/granted (seed_permissions.sql):
--   NURSING_RECORD_VITALS        -> NURSE + DOCTOR (write)
--   CLINICAL_VIEW_VITALS_HISTORY -> NURSE + DOCTOR + ADMIN (read)
-- so no permission migration is needed here.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Safe to re-run.
CREATE TABLE IF NOT EXISTS admission_vitals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    recorded_at   DATETIME NOT NULL,            -- clinical time the reading was taken
    temp_c        DECIMAL(4,1) NULL,            -- e.g. 37.2
    pulse_bpm     INT NULL,
    resp_rate     INT NULL,
    systolic_bp   INT NULL,
    diastolic_bp  INT NULL,
    spo2_pct      INT NULL,
    blood_glucose DECIMAL(5,1) NULL,            -- mg/dL
    weight_kg     DECIMAL(5,1) NULL,            -- pediatric use
    height_cm     DECIMAL(5,1) NULL,
    ofc_cm        DECIMAL(5,1) NULL,            -- head circumference (pediatric)
    pain_score    TINYINT NULL,                 -- 0-10
    notes         VARCHAR(500) NULL,
    recorded_by_id INT NOT NULL,
    recorded_by_role ENUM('NURSE','DOCTOR','ADMIN','MANAGER','RECEPTIONIST') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_id) REFERENCES users(id),
    INDEX idx_vitals_admission (admission_id, recorded_at)
);
