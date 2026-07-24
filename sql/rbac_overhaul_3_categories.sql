-- ============================================================================
-- RBAC OVERHAUL — STEP 3: fix permission grouping on the assign-permissions UI
-- (2026-07-24)
--
-- The catalog only had 3 categories (clinical / financial / admin), so the
-- Permissions screen jammed unrelated capabilities under one heading:
--   * "Admin & Reception" mixed SYSTEM config (assign permissions, configure
--     financial settings) with FRONT-DESK work (capture payment, edit timings).
--   * "Clinical & Nursing" mixed doctor CLINICAL views with nurse WARD work.
--
-- This splits `category` into five role-owned buckets that match the redesign's
-- headings. Data-only; re-categorizes existing rows, adds/removes nothing.
--
-- Categories after this migration:
--   nursing    — Nursing & ward
--   clinical   — Clinical (doctor)
--   reception  — Front desk & reception
--   financial  — Money & billing
--   admin      — System administration
--
-- Idempotent: pure UPDATE ... WHERE `key` IN (...). Safe to re-run.
-- ============================================================================

-- Nursing & ward.
UPDATE permissions SET category = 'nursing' WHERE `key` IN (
    'NURSING_RECORD_VITALS',
    'NURSING_ATTEND_SHORT_STAY',
    'NURSING_LOG_CHARGEABLE_EVENTS',
    'NURSING_DISCHARGE_PATIENT',
    'NURSING_SKIP_ROTATION',
    'NURSING_SELF_ATTEND',
    'NURSING_PERFORM_PROCEDURES',
    'NURSING_RECORD_ADMISSIONS',
    'ADMISSION_ADMIT_PATIENT'
);

-- Clinical (doctor-facing record views + notes).
UPDATE permissions SET category = 'clinical' WHERE `key` IN (
    'CLINICAL_VIEW_MEDICAL_RECORD',
    'CLINICAL_VIEW_CONSULTATION_NOTES',
    'CLINICAL_VIEW_VITALS_HISTORY',
    'CLINICAL_VIEW_PAST_PROCEDURES',
    'CLINICAL_ADD_NOTES'
);

-- Front desk & reception.
UPDATE permissions SET category = 'reception' WHERE `key` IN (
    'RECEPTION_REGISTER_PATIENTS',
    'RECEPTION_MANAGE_BOOKINGS',
    'RECEPTION_EDIT_DOCTOR_TIMINGS',
    'RECEPTION_GENERATE_OPD_SLIPS',
    'RECEPTION_CAPTURE_PAYMENT_MODE',
    'RECEPTION_PRINT_CONSENT',
    'RECEPTION_UPLOAD_CONSENT'
);

-- Money & billing (collect / invoice / refund / close / expenses / settle).
UPDATE permissions SET category = 'financial' WHERE `key` IN (
    'RECEPTION_GENERATE_INVOICES',
    'RECEPTION_PROCESS_PAYMENTS',
    'RECEPTION_ISSUE_REFUNDS',
    'RECEPTION_CLOSE_DAY',
    'ADMIN_RECEIVE_HANDOVER',
    'ADMISSION_FINALIZE_BILL',
    'ADMISSION_APPROVE_WRITEOFF',
    'FINANCIAL_POST_EXPENSES',
    'FINANCIAL_APPROVE_EXPENSES',
    'FINANCIAL_VIEW_OWN_EARNINGS',
    'FINANCIAL_VIEW_ALL_COMMISSIONS',
    'FINANCIAL_VIEW_CLINIC_REPORTS',
    'FINANCIAL_VIEW_DAILY_PL',
    'FINANCIAL_VIEW_INVOICES'
);

-- System administration (user / permission / config / audit).
UPDATE permissions SET category = 'admin' WHERE `key` IN (
    'ADMIN_MANAGE_USERS',
    'ADMIN_ASSIGN_PERMISSIONS',
    'ADMIN_EDIT_STAFF_DETAILS',
    'ADMIN_VIEW_AUDIT_LOGS',
    'ADMIN_MANAGE_PROCEDURE_MASTER',
    'ADMIN_MANAGE_CONSENT_TEMPLATES',
    'ADMIN_MANAGE_EXPENSE_CATEGORIES',
    'ADMIN_CONFIGURE_FINANCIAL_SETTINGS',
    'ADMIN_CONFIGURE_COMMISSION_SETTINGS'
);
