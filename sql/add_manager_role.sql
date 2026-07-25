-- ============================================================================
-- ADD BACK A MANAGER ROLE — as a permission preset, not a name-based gate.
-- (2026-07-25)
--
-- On 2026-07-24 the roles were collapsed to ADMIN/DOCTOR/STAFF and ALL access
-- was made permission-driven (menus + page gates read has_permission(), never a
-- role name — see collapse_roles_to_staff.sql / rbac_overhaul_*). This migration
-- re-introduces MANAGER WITHOUT undoing that: MANAGER is a 4th base_role whose
-- access comes entirely from a pre-granted bundle in role_permissions. Nothing in
-- the code checks the string 'MANAGER' — so every existing menu and gate keeps
-- working unchanged. A manager is simply "a STAFF-shaped account that ships with
-- the oversight bundle already ticked."
--
-- ADMIN-assigns-to-STAFF (the "some staff get some managerial powers" case) needs
-- NO migration — that already works today via user_permission_overrides: grant an
-- individual STAFF member FINANCIAL_APPROVE_EXPENSES (etc.) on the Staff screen and
-- they immediately gain the power (and, for expenses, the approval emails).
--
-- WHAT THIS DOES
--   1. Widen the users + role_permissions enums to include 'MANAGER'.
--   2. Add a new permission CATEGORY 'managerial' and move the oversight keys into
--      it so they group together on the Permissions / Staff screens.
--   3. Grant MANAGER its default bundle (approve expenses [covers over-limit
--      approval], void/corrections, write-off approval, financial oversight views).
--
-- Idempotent. ADDITIVE — no user is moved, no grant is dropped. Safe to re-run.
-- Run in phpMyAdmin BEFORE deploying the matching code (staff.php picker,
-- permissions.php category label). Order inside the file is safe in any sequence.
-- ============================================================================

-- ---- 1. Widen both enums to include MANAGER (keeps ADMIN/DOCTOR/STAFF) ----
ALTER TABLE users
    MODIFY base_role ENUM('ADMIN','DOCTOR','MANAGER','STAFF') NOT NULL;
ALTER TABLE role_permissions
    MODIFY base_role ENUM('ADMIN','DOCTOR','MANAGER','STAFF') NOT NULL;

-- ---- 2. New 'managerial' category + re-home the oversight keys into it -------
-- permissions.category is VARCHAR(20) since rbac_overhaul_4_fix_category_enum.sql,
-- so these UPDATEs stick (an ENUM would silently truncate — that bug cost real
-- time on 2026-07-24; see project_hmis_rbac_overhaul). Keys that don't exist yet
-- are simply not matched — harmless.
UPDATE permissions SET category = 'managerial'
 WHERE `key` IN (
    'FINANCIAL_APPROVE_EXPENSES',   -- sign off posted expenses (incl. over-limit)
    'FINANCIAL_VOID_BILL',          -- reverse bills / admission bills / refunds
    'ADMISSION_APPROVE_WRITEOFF',   -- approve admission-bill write-offs
    'FINANCIAL_VIEW_CLINIC_REPORTS',
    'FINANCIAL_VIEW_ALL_COMMISSIONS',
    'FINANCIAL_VIEW_DAILY_PL',
    'ADMIN_RECEIVE_HANDOVER'        -- receive end-of-day cash handover
 );

-- ---- 3. Grant MANAGER its default oversight bundle --------------------------
-- One INSERT per key, guarded by NOT EXISTS so a re-run adds nothing. Only keys
-- that exist in the catalog are granted (the JOIN drops any missing key).
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'MANAGER', p.id
FROM permissions p
WHERE p.`key` IN (
    -- The power that started this thread + over-limit approvals (same key: an
    -- over-limit expense is just FLAGGED, it isn't a separate permission).
    'FINANCIAL_APPROVE_EXPENSES',
    -- Financial corrections / oversight.
    'FINANCIAL_VOID_BILL',
    'ADMISSION_APPROVE_WRITEOFF',
    -- Oversight views (seeded but not yet wired in code — forward-looking, harmless).
    'FINANCIAL_VIEW_CLINIC_REPORTS',
    'FINANCIAL_VIEW_ALL_COMMISSIONS',
    'FINANCIAL_VIEW_DAILY_PL',
    -- Day-control / cash oversight.
    'ADMIN_RECEIVE_HANDOVER',
    'RECEPTION_CLOSE_DAY'
)
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = 'MANAGER' AND rp.permission_id = p.id
);

-- ---- Verify (read-only) ----
-- Expect the MANAGER row to list the bundle above, and the managerial keys to
-- show category = 'managerial'.
SELECT p.`key`, p.category
  FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE rp.base_role = 'MANAGER'
 ORDER BY p.category, p.`key`;
