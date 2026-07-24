-- ============================================================================
-- RBAC OVERHAUL — STEP 4: the category re-grouping never took. Here's why + fix.
-- (2026-07-24)
--
-- ROOT CAUSE: permissions.category was ENUM('clinical','financial','admin'). Every
-- earlier attempt to set it to 'reception' or 'nursing' silently TRUNCATED to ''
-- (MySQL warning #1265), so rbac_overhaul_3_categories.sql appeared to "run" but
-- changed nothing — the column physically rejected the new values.
--
-- FIX: convert category to VARCHAR (freely extensible, no ENUM edits ever again),
-- THEN re-categorize into the five role-owned buckets, THEN retire the legacy
-- admit key. Order matters: widen the column before writing the new values.
--
-- Idempotent. Safe to paste into phpMyAdmin repeatedly.
-- ============================================================================

-- 1. Widen the column so it accepts the new category names.
ALTER TABLE permissions
    MODIFY COLUMN category VARCHAR(20) NOT NULL DEFAULT 'admin';

-- 2. Heal any rows that got truncated to '' by the failed earlier runs, then
--    (re)assign every key to its correct bucket. These UPDATEs are the whole
--    point — with the column now VARCHAR they finally stick.

-- Nursing & ward.
UPDATE permissions SET category = 'nursing' WHERE `key` IN (
    'NURSING_RECORD_VITALS','NURSING_ATTEND_SHORT_STAY','NURSING_LOG_CHARGEABLE_EVENTS',
    'NURSING_DISCHARGE_PATIENT','NURSING_SKIP_ROTATION','NURSING_SELF_ATTEND',
    'NURSING_PERFORM_PROCEDURES','NURSING_RECORD_ADMISSIONS','ADMISSION_ADMIT_PATIENT'
);

-- Clinical (doctor).
UPDATE permissions SET category = 'clinical' WHERE `key` IN (
    'CLINICAL_VIEW_MEDICAL_RECORD','CLINICAL_VIEW_CONSULTATION_NOTES','CLINICAL_VIEW_VITALS_HISTORY',
    'CLINICAL_VIEW_PAST_PROCEDURES','CLINICAL_ADD_NOTES'
);

-- Front desk & reception.
UPDATE permissions SET category = 'reception' WHERE `key` IN (
    'RECEPTION_REGISTER_PATIENTS','RECEPTION_MANAGE_BOOKINGS','RECEPTION_EDIT_DOCTOR_TIMINGS',
    'RECEPTION_GENERATE_OPD_SLIPS','RECEPTION_CAPTURE_PAYMENT_MODE',
    'RECEPTION_PRINT_CONSENT','RECEPTION_UPLOAD_CONSENT'
);

-- Money & billing.
UPDATE permissions SET category = 'financial' WHERE `key` IN (
    'RECEPTION_GENERATE_INVOICES','RECEPTION_PROCESS_PAYMENTS','RECEPTION_ISSUE_REFUNDS',
    'RECEPTION_CLOSE_DAY','ADMIN_RECEIVE_HANDOVER','ADMISSION_FINALIZE_BILL',
    'ADMISSION_APPROVE_WRITEOFF','FINANCIAL_POST_EXPENSES','FINANCIAL_APPROVE_EXPENSES',
    'FINANCIAL_VIEW_OWN_EARNINGS','FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_CLINIC_REPORTS',
    'FINANCIAL_VIEW_DAILY_PL','FINANCIAL_VIEW_INVOICES'
);

-- System administration.
UPDATE permissions SET category = 'admin' WHERE `key` IN (
    'ADMIN_MANAGE_USERS','ADMIN_ASSIGN_PERMISSIONS','ADMIN_EDIT_STAFF_DETAILS','ADMIN_VIEW_AUDIT_LOGS',
    'ADMIN_MANAGE_PROCEDURE_MASTER','ADMIN_MANAGE_CONSENT_TEMPLATES','ADMIN_MANAGE_EXPENSE_CATEGORIES',
    'ADMIN_CONFIGURE_FINANCIAL_SETTINGS','ADMIN_CONFIGURE_COMMISSION_SETTINGS'
);

-- 3. Retire the legacy admit key. Nothing in code checks RECEPTION_ADMIT_PATIENTS
--    anymore (merged into ADMISSION_ADMIT_PATIENT, grants already copied over), so
--    remove it from the catalog to stop the duplicate "Admit a patient" row.
--    FK cascades clean up role_permissions / user_permission_overrides rows.
DELETE FROM permissions WHERE `key` = 'RECEPTION_ADMIT_PATIENTS';

-- 4. Verify: every key should now sit in one of the five buckets, none in ''.
--    (Read-only — expect zero rows.)
-- SELECT `key`, category FROM permissions
-- WHERE category NOT IN ('nursing','clinical','reception','financial','admin');
