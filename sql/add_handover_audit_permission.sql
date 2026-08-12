-- ============================================================================
-- SPLIT CASH-HANDOVER "AUDIT" FROM "RECEIVE" — new ADMIN_AUDIT_HANDOVER key
-- (2026-08-12)
--
-- Today ADMIN_RECEIVE_HANDOVER is one permission that both (a) opens
-- admin_handovers.php's pending/history/drill-down views and (b) lets the
-- holder mark a handover received or close a stranded shift on a
-- receptionist's behalf. A MANAGER who should be able to monitor/audit cash
-- handovers without being able to act on them like ADMIN needs (a) without
-- (b) — this migration adds that as its own key, per project convention
-- ("Add a Mode, Don't Replace the Control": the receive path stays exactly
-- as flexible as it is today for whoever holds ADMIN_RECEIVE_HANDOVER).
--
-- WHAT THIS DOES
--   1. Add permission ADMIN_AUDIT_HANDOVER ("View & audit cash handovers",
--      category financial — same bucket as ADMIN_RECEIVE_HANDOVER).
--   2. Grant it to ADMIN (already implied by holding everything, granted
--      explicitly anyway so the Permissions screen shows it checked) and to
--      MANAGER (see sql/add_manager_role.sql; NOT_EXISTS-guarded so this runs
--      fine whether that migration has already run or not — the INSERT
--      simply matches zero MANAGER rows in role_permissions until it has).
--
-- Idempotent. ADDITIVE — no existing grant is touched. Safe to re-run.
-- Run in phpMyAdmin BEFORE deploying the matching code (admin_handovers.php
-- audit-vs-receive split, sidebar.php nav entry, permissions.php role list).
-- ============================================================================

INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'ADMIN_AUDIT_HANDOVER' AS `key`,
           'View & audit cash handovers (read-only)' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'ADMIN' AS base_role, 'ADMIN_AUDIT_HANDOVER' AS pkey
    UNION ALL SELECT 'MANAGER', 'ADMIN_AUDIT_HANDOVER'
) r
JOIN permissions p ON p.`key` = r.pkey
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- ---- Verify (read-only) ----
SELECT rp.base_role, p.`key`, p.category
  FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE p.`key` IN ('ADMIN_AUDIT_HANDOVER', 'ADMIN_RECEIVE_HANDOVER')
 ORDER BY p.`key`, rp.base_role;
