-- =============================================================================
-- ER short-stay — the nursing care flow-sheet.
--
-- WHY: the ER short-stay path records vitals (admission_vitals) and handovers
-- (admission_handovers), but had NOWHERE to write a nursing action that carries
-- no charge. Its only free text was admission_services.clinical_note, which
-- hangs off a CHARGEABLE line — so "IV line flushed", "patient tolerating oral
-- feeds", "family counselled" simply could not be recorded on an ER stay.
--
-- This is the same hole IPD closed with ipd_care_events. This migration gives
-- the ER path its own mirror of that table, deliberately SEPARATE from the IPD
-- one (matching add_ipd_admissions.sql's stance that the two modules stay
-- independent) and keyed to admissions(id) rather than ipd_admissions(id).
--
-- The ENUM includes SERVICE from the outset — IPD had to widen its enum later
-- (add_ipd_care_service_events.sql) once it wanted logged services mirrored into
-- the clinical timeline. Starting wide avoids repeating that migration here.
--
-- ER services themselves are UNTOUCHED by this migration: admission_services
-- keeps its own table, its own form and its own billing path exactly as they are.
--
-- No stored procedures — the DB user is denied CREATE ROUTINE (#1044) — so the
-- table is plain CREATE TABLE IF NOT EXISTS and the permission seeds are
-- WHERE NOT EXISTS guarded. Idempotent: safe to run more than once.
--
-- Schema is named EXPLICITLY, never DATABASE(): phpMyAdmin often lands you in
-- information_schema, where DATABASE() resolves there and the statement either
-- fails or silently targets the wrong schema.
--
-- All timestamps are PKT (+05:00). Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. The care-event table.
--
-- Column-for-column mirror of ipd_care_events, so the two stay pages render the
-- same shape and a future merge is a rename rather than a reconciliation.
--
-- ref_table / ref_id point back at a source row (a handover, a service) for
-- events written by system code rather than typed by a nurse.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u402528120_hmis.admission_care_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    event_type ENUM('DOCTOR_VISIT','NURSING_CARE','MEDICATION','OBSERVATION','HANDOVER','SERVICE','OTHER') NOT NULL,
    ref_table VARCHAR(40) NULL,
    ref_id INT NULL,
    note VARCHAR(1000) NULL,
    logged_by_id INT NOT NULL,
    logged_by_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL,
    event_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_adm_care_admission (admission_id, event_at),
    FOREIGN KEY (admission_id) REFERENCES u402528120_hmis.admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (logged_by_id) REFERENCES u402528120_hmis.users(id)
);

-- -----------------------------------------------------------------------------
-- 2. Permission.
--
-- A NEW key rather than a reuse:
--   * IPD_LOG_CARE would cross the deliberate ER/IPD permission boundary that
--     docs/IPD_MODULE_PLAN.md sets out ("Do NOT reuse ER NURSING_* perms" — the
--     same separation applies in reverse).
--   * NURSING_LOG_CHARGEABLE_EVENTS is about BILLABLE services; conflating it
--     with un-billed care is exactly the confusion this table exists to end.
--
-- Category 'nursing' matches the other NURSING_* keys, so it lands in the same
-- section of the Permissions screen as the rest of the ER nursing set.
-- -----------------------------------------------------------------------------
INSERT INTO u402528120_hmis.permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'NURSING_LOG_CARE' AS `key`,
           'Log ER nursing care / flow-sheet events' AS label,
           'nursing' AS category
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM u402528120_hmis.permissions p WHERE p.`key` = seed.`key`
);

-- NURSING_LOG_CARE -> admin + staff, matching IPD_LOG_CARE's grant.
-- Doctors are deliberately excluded from WRITING nursing care, exactly as on the
-- IPD side; they still READ the flow-sheet (the stay page gates reading on view
-- access, not on this key).
INSERT INTO u402528120_hmis.role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF') r
JOIN u402528120_hmis.permissions p ON p.`key` = 'NURSING_LOG_CARE'
WHERE NOT EXISTS (
    SELECT 1 FROM u402528120_hmis.role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- =============================================================================
-- Verify (expect: table present, 1 permission row, 2 role grants).
-- =============================================================================
SELECT 'admission_care_events' AS object,
       COUNT(*) AS present
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'admission_care_events'
UNION ALL
SELECT 'NURSING_LOG_CARE permission',
       COUNT(*) FROM u402528120_hmis.permissions WHERE `key` = 'NURSING_LOG_CARE'
UNION ALL
SELECT 'NURSING_LOG_CARE role grants',
       COUNT(*) FROM u402528120_hmis.role_permissions rp
       JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
       WHERE p.`key` = 'NURSING_LOG_CARE';

-- =============================================================================
-- End ER care flow-sheet migration.
-- =============================================================================
