-- =============================================================================
-- Finances + Analytics reports are ADMIN-ONLY by default (2026-07-27)
--
-- A live audit found the three finance-view keys granted as ROLE DEFAULTS to
-- STAFF and MANAGER as well as ADMIN. In practice that meant every receptionist
-- could open:
--
--     Doctor Share Statement   FINANCIAL_VIEW_ALL_COMMISSIONS
--     Profit & Loss            FINANCIAL_VIEW_DAILY_PL
--     Tax Register             FINANCIAL_VIEW_ALL_COMMISSIONS
--     Income Report            FINANCIAL_VIEW_CLINIC_REPORTS
--     Expense Report           FINANCIAL_VIEW_CLINIC_REPORTS
--     (Doctor Payouts          FINANCIAL_RUN_PAYOUT — already admin-only)
--
-- The PAGES were never the problem: each already calls require_permission().
-- The grants were. Confirmed policy: nobody but ADMIN holds these by default;
-- anyone else gets them only where the admin explicitly grants them per user.
--
-- HOW STAFF GOT THEM — it was never a decision. collapse_roles_to_staff.sql
-- Phase 2 folded the UNION of the MANAGER / ACCOUNTANT / NURSE / RECEPTIONIST
-- grants into the new STAFF role. ACCOUNTANT and MANAGER both held the finance
-- keys (see seed_permissions.sql), so every receptionist silently inherited the
-- accountant's report access as a side effect of the three-role merge.
-- seed_permissions.sql has since been amended so a re-seed cannot reintroduce
-- this, but that file only ever granted to the LEGACY roles, which no longer
-- exist — this migration is what fixes the live database.
--
-- MANAGER keeps its other oversight powers (approve expenses, void bills,
-- receive handover, close day) — only the report VIEWS are revoked.
--
-- PER-USER GRANTS ARE NOT TOUCHED. user_permission_overrides is left alone, so
-- anyone the admin deliberately granted access keeps it. That table is exactly
-- the "admin has granted permission" path, and revoking it would undo the
-- admin's own decisions.
--
-- NOTE: permissions are cached in $_SESSION at login
-- (config/permissions.php::refresh_session_permissions). A user who is already
-- logged in keeps the old set until they log out and back in.
--
-- Flat and idempotent; no stored procedures (Hostinger denies CREATE ROUTINE,
-- #1044). Safe to re-run.
-- Run in phpMyAdmin against the hims database.
-- =============================================================================

-- 1. Strip the finance keys from every role default EXCEPT ADMIN.
DELETE rp FROM u402528120_hmis.role_permissions rp
  JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
 WHERE p.`key` IN (
        'FINANCIAL_VIEW_ALL_COMMISSIONS',
        'FINANCIAL_VIEW_DAILY_PL',
        'FINANCIAL_VIEW_CLINIC_REPORTS',
        'FINANCIAL_RUN_PAYOUT'
       )
   AND rp.base_role <> 'ADMIN';

-- 2. Belt and braces: make sure ADMIN actually holds all four, so locking the
--    others down can never leave the reports unreachable by anyone.
INSERT IGNORE INTO u402528120_hmis.role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id
  FROM u402528120_hmis.permissions p
 WHERE p.`key` IN (
        'FINANCIAL_VIEW_ALL_COMMISSIONS',
        'FINANCIAL_VIEW_DAILY_PL',
        'FINANCIAL_VIEW_CLINIC_REPORTS',
        'FINANCIAL_RUN_PAYOUT'
       );

-- =============================================================================
-- Verify — expect ONLY 'ROLE DEFAULT / ADMIN' rows, plus any deliberate
-- per-user grants the admin made. Fully qualified: an information_schema query
-- switches phpMyAdmin's current-database context.
--
--   SELECT 'ROLE DEFAULT' AS via, rp.base_role AS who, p.`key` AS permission
--     FROM u402528120_hmis.role_permissions rp
--     JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
--    WHERE p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_DAILY_PL',
--                      'FINANCIAL_VIEW_CLINIC_REPORTS','FINANCIAL_RUN_PAYOUT')
--   UNION ALL
--   SELECT CONCAT('USER OVERRIDE (', IF(o.granted=1,'GRANT','revoke'), ')'),
--          CONCAT(u.name,' [',u.base_role,']'), p.`key`
--     FROM u402528120_hmis.user_permission_overrides o
--     JOIN u402528120_hmis.permissions p ON p.id = o.permission_id
--     JOIN u402528120_hmis.users u ON u.id = o.user_id
--    WHERE p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_DAILY_PL',
--                      'FINANCIAL_VIEW_CLINIC_REPORTS','FINANCIAL_RUN_PAYOUT')
--    ORDER BY via, who, permission;
-- =============================================================================
