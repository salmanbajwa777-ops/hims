-- ============================================================================
-- RBAC OVERHAUL — STEP 2 of 3: grant the real keys so code can stop hardcoding
-- (2026-07-24)  ·  RUN sql/rbac_overhaul_1_catalog.sql FIRST.
--
-- Today nearly every gate reads:
--     has_permission(X) || in_array($baseRole, ['ADMIN','MANAGER', ...])
-- The in_array(...) is an invisible second grant path. Before we can delete it
-- from the code (step 3), the DB must grant those same keys to those same roles
-- — otherwise removing the fallback would lock people out.
--
-- This migration reproduces EVERY hardcoded role->key path found in the audit as
-- a real role_permissions grant, and back-grants the three new split keys to
-- whoever holds their parent key today. It ADDS access only; it drops nothing.
--
-- GRANT-BEFORE-REVOKE: this must be proven live before any code hardcode is
-- removed. After running, users get the new grants on their next page load
-- (refresh_session_permissions() reloads every request).
--
-- Idempotent: INSERT ... WHERE NOT EXISTS per grant. Safe to re-run.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Helper shape used throughout: grant a list of (role,key) pairs, skipping any
-- that already exist. Expressed inline as a derived table per block.
-- ---------------------------------------------------------------------------

-- 1. ADMIN gets EVERYTHING. (Re-assert; this is what lets us drop the ADMIN
--    branch of every in_array shortcut.)
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id FROM permissions p
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'ADMIN' AND rp.permission_id = p.id
);

-- 2. Reproduce every hardcoded role->key path from the gate audit as a real
--    grant. Each pair below corresponds to an in_array($baseRole,[...]) branch
--    in the PHP that step 3 will delete.
INSERT INTO role_permissions (base_role, permission_id)
SELECT g.base_role, p.id
FROM (
    -- admission.php / admissions.php / admission_discharge.php: ADMIN,MANAGER
    -- reach billing + write-off + vitals-history via hardcode.
              SELECT 'MANAGER' AS base_role, 'RECEPTION_PROCESS_PAYMENTS' AS `key`
    UNION ALL SELECT 'MANAGER', 'ADMISSION_APPROVE_WRITEOFF'
    UNION ALL SELECT 'MANAGER', 'CLINICAL_VIEW_VITALS_HISTORY'
    UNION ALL SELECT 'MANAGER', 'NURSING_RECORD_VITALS'
    UNION ALL SELECT 'MANAGER', 'NURSING_LOG_CHARGEABLE_EVENTS'
    UNION ALL SELECT 'MANAGER', 'NURSING_DISCHARGE_PATIENT'
    UNION ALL SELECT 'MANAGER', 'NURSING_RECORD_ADMISSIONS'      -- admission.php $canView
    UNION ALL SELECT 'MANAGER', 'ADMISSION_ADMIT_PATIENT'

    -- admission.php $canLog / $canDischarge: RECEPTIONIST reaches these by role.
    UNION ALL SELECT 'RECEPTIONIST', 'NURSING_LOG_CHARGEABLE_EVENTS'
    UNION ALL SELECT 'RECEPTIONIST', 'NURSING_DISCHARGE_PATIENT'

    -- admission.php $canLog: NURSE logs chargeable events by role.
    UNION ALL SELECT 'NURSE', 'NURSING_LOG_CHARGEABLE_EVENTS'

    -- admission.php $canView: NURSE views the ward by role (already has clinical
    -- via category, but record-admissions makes the intent explicit).
    UNION ALL SELECT 'NURSE', 'NURSING_RECORD_ADMISSIONS'
) AS g
JOIN permissions p ON p.`key` = g.`key`
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = g.base_role AND rp.permission_id = p.id
);

-- 3. Back-grant the NEW split keys to every role/user that holds the PARENT key
--    today, so splitting the code gate (step 3) takes nobody's access away.
--
--    RECEPTION_MANAGE_BOOKINGS  <- parent RECEPTION_REGISTER_PATIENTS
--    RECEPTION_EDIT_DOCTOR_TIMINGS <- parent RECEPTION_REGISTER_PATIENTS
--    ADMISSION_FINALIZE_BILL    <- parent RECEPTION_PROCESS_PAYMENTS
-- ---- role-level back-grant ----
INSERT INTO role_permissions (base_role, permission_id)
SELECT DISTINCT rp.base_role, child.id
FROM role_permissions rp
JOIN permissions parent ON parent.id = rp.permission_id
JOIN permissions child  ON (
        (parent.`key` = 'RECEPTION_REGISTER_PATIENTS' AND child.`key` IN ('RECEPTION_MANAGE_BOOKINGS','RECEPTION_EDIT_DOCTOR_TIMINGS'))
     OR (parent.`key` = 'RECEPTION_PROCESS_PAYMENTS'  AND child.`key` = 'ADMISSION_FINALIZE_BILL')
)
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp2 WHERE rp2.base_role = rp.base_role AND rp2.permission_id = child.id
);

-- ---- per-user back-grant (someone granted the parent individually) ----
INSERT INTO user_permission_overrides (user_id, permission_id, granted)
SELECT DISTINCT o.user_id, child.id, 1
FROM user_permission_overrides o
JOIN permissions parent ON parent.id = o.permission_id AND o.granted = 1
JOIN permissions child  ON (
        (parent.`key` = 'RECEPTION_REGISTER_PATIENTS' AND child.`key` IN ('RECEPTION_MANAGE_BOOKINGS','RECEPTION_EDIT_DOCTOR_TIMINGS'))
     OR (parent.`key` = 'RECEPTION_PROCESS_PAYMENTS'  AND child.`key` = 'ADMISSION_FINALIZE_BILL')
)
WHERE NOT EXISTS (
    SELECT 1 FROM user_permission_overrides o2
    WHERE o2.user_id = o.user_id AND o2.permission_id = child.id
);

-- 3b. ADMIT MERGE: back-grant ADMISSION_ADMIT_PATIENT to every role AND user that
--     currently holds the legacy RECEPTION_ADMIT_PATIENTS, so the code can drop the
--     legacy key from its OR-conditions (step 3) without anyone losing admit. This
--     folds sql/fix_admit_permission_unify.sql's backfill into the overhaul set so
--     a single run makes the OR-retirement safe. Revokes (granted=0) are NOT mirrored.
INSERT INTO role_permissions (base_role, permission_id)
SELECT DISTINCT rp_old.base_role, p_new.id
FROM role_permissions rp_old
JOIN permissions p_old ON p_old.id = rp_old.permission_id AND p_old.`key` = 'RECEPTION_ADMIT_PATIENTS'
JOIN permissions p_new ON p_new.`key` = 'ADMISSION_ADMIT_PATIENT'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp2 WHERE rp2.base_role = rp_old.base_role AND rp2.permission_id = p_new.id
);

INSERT INTO user_permission_overrides (user_id, permission_id, granted)
SELECT o.user_id, p_new.id, 1
FROM user_permission_overrides o
JOIN permissions p_old ON p_old.id = o.permission_id AND p_old.`key` = 'RECEPTION_ADMIT_PATIENTS' AND o.granted = 1
JOIN permissions p_new ON p_new.`key` = 'ADMISSION_ADMIT_PATIENT'
WHERE NOT EXISTS (
    SELECT 1 FROM user_permission_overrides o2 WHERE o2.user_id = o.user_id AND o2.permission_id = p_new.id
);

-- 4. FINANCIAL_APPROVE_EXPENSES: enforce the two-person rule. Posting spends
--    cash (FINANCIAL_POST_EXPENSES, held by reception); approving signs it off
--    and belongs to MANAGER (+ ADMIN, already covered by block 1). Grant to
--    MANAGER, do NOT grant to whoever posts.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'MANAGER', p.id FROM permissions p
WHERE p.`key` = 'FINANCIAL_APPROVE_EXPENSES'
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'MANAGER' AND rp.permission_id = p.id
);
