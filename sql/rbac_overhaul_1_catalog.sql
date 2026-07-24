-- ============================================================================
-- RBAC OVERHAUL — STEP 1 of 3: consolidate the permission catalog
-- (2026-07-24)
--
-- Folds every real, in-use permission key into ONE place: the `permissions`
-- catalog. Before this, the "master" seed_permissions.sql listed only ~34 keys
-- while another ~7 real keys (refunds, close-day, handover, expenses,
-- write-off, admit) were seeded in scattered feature migrations. This migration
-- re-asserts all of them idempotently and ADDS the three new split keys the
-- overhaul introduces.
--
-- ADDITIVE ONLY. No key is removed and no grant is dropped here — this file is
-- safe to run first with zero lockout risk. Grants live in step 2; code changes
-- come after both SQL steps are proven.
--
-- Idempotent: INSERT ... WHERE NOT EXISTS per key. Safe to re-run.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- A. Re-assert every key that was seeded only in a scattered feature migration
--    so the master catalog is the single source of truth. (If the feature
--    migration already ran, these are no-ops.)
-- ---------------------------------------------------------------------------
-- Categories here already match the five-bucket scheme (rbac_overhaul_3_categories.sql);
-- run in any order — step 3 also corrects any pre-existing rows.
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'ADMISSION_ADMIT_PATIENT'   AS `key`, 'Admit a patient to ER / short-stay (doctor or reception)' AS label, 'nursing'  AS category
    UNION ALL SELECT 'ADMISSION_APPROVE_WRITEOFF', 'Approve an admission-bill write-off',                'financial'
    UNION ALL SELECT 'RECEPTION_ISSUE_REFUNDS',    'Issue refunds',                                      'financial'
    UNION ALL SELECT 'RECEPTION_CLOSE_DAY',        'Close the day / cash tally',                         'financial'
    UNION ALL SELECT 'ADMIN_RECEIVE_HANDOVER',     'Receive end-of-day cash handover',                   'financial'
    UNION ALL SELECT 'FINANCIAL_POST_EXPENSES',    'Post petty-cash expenses',                           'financial'
    UNION ALL SELECT 'FINANCIAL_APPROVE_EXPENSES', 'Approve / reject posted expenses',                   'financial'
    UNION ALL SELECT 'NURSING_RECORD_ADMISSIONS',  'Record short-stay admissions',                       'nursing'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- ---------------------------------------------------------------------------
-- B. NEW split keys introduced by the overhaul. Each carves a narrow capability
--    out of an over-broad parent so a clerk can be scoped without full rights.
--      RECEPTION_REGISTER_PATIENTS  ->  + RECEPTION_MANAGE_BOOKINGS
--                                       + RECEPTION_EDIT_DOCTOR_TIMINGS
--      RECEPTION_PROCESS_PAYMENTS   ->  + ADMISSION_FINALIZE_BILL (discharge settle)
-- ---------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'RECEPTION_MANAGE_BOOKINGS'    AS `key`, 'Take / manage phone bookings'                 AS label, 'reception' AS category
    UNION ALL SELECT 'RECEPTION_EDIT_DOCTOR_TIMINGS', 'Edit doctor day-timings sheet',             'reception'
    UNION ALL SELECT 'ADMISSION_FINALIZE_BILL',       'Finalize / settle an admission (discharge)', 'financial'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);
