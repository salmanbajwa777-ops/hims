-- ============================================================================
-- READ-ONLY audit: who can reach the Finances + Analytics report pages?
--
-- Nothing here writes. Paste into phpMyAdmin (DB `u402528120_hmis`) and run
-- each block; every table is fully qualified so it works from any default DB.
--
-- The five menu items in question and the key each one needs:
--   Doctor Share Statement  -> FINANCIAL_VIEW_ALL_COMMISSIONS
--   Profit & Loss           -> FINANCIAL_VIEW_DAILY_PL
--   Tax Register            -> FINANCIAL_VIEW_ALL_COMMISSIONS
--   Income Report           -> FINANCIAL_VIEW_CLINIC_REPORTS
--   Expense Report          -> FINANCIAL_VIEW_CLINIC_REPORTS
-- (Doctor Payouts, in the same group, needs FINANCIAL_RUN_PAYOUT.)
-- ============================================================================


-- 1. Which ROLES currently hold each of these keys ---------------------------
--    This is the default grant everyone of that role inherits.
SELECT p.`key`            AS permission,
       p.label,
       rp.base_role       AS granted_to_role
FROM   permissions p
JOIN   role_permissions rp ON rp.permission_id = p.id
WHERE  p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS',
                   'FINANCIAL_VIEW_CLINIC_REPORTS',
                   'FINANCIAL_VIEW_DAILY_PL',
                   'FINANCIAL_RUN_PAYOUT')
ORDER  BY p.`key`, rp.base_role;


-- 2. EFFECTIVE access per user (role grant + per-user override) --------------
--    This is the real answer: exactly who can open these pages right now.
--    'via' tells you WHERE the access comes from, which decides how to remove
--    it — a role grant is stripped on permissions.php, an override on staff.php.
SELECT u.id,
       u.name,
       u.base_role,
       p.`key` AS permission,
       CASE
           WHEN o.granted = 1 THEN 'USER OVERRIDE (granted)'
           WHEN rp.id IS NOT NULL THEN CONCAT('role default (', u.base_role, ')')
       END AS via
FROM   users u
JOIN   permissions p
         ON p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS',
                        'FINANCIAL_VIEW_CLINIC_REPORTS',
                        'FINANCIAL_VIEW_DAILY_PL',
                        'FINANCIAL_RUN_PAYOUT')
LEFT   JOIN role_permissions rp
         ON rp.permission_id = p.id AND rp.base_role = u.base_role
LEFT   JOIN user_permission_overrides o
         ON o.permission_id = p.id AND o.user_id = u.id
WHERE  u.is_active = 1
  -- effective = override says granted, OR role grants it and no revoke override
  AND (o.granted = 1 OR (rp.id IS NOT NULL AND (o.granted IS NULL)))
ORDER  BY u.base_role, u.name, p.`key`;


-- 3. Any per-user OVERRIDES already in place on these keys -------------------
--    granted=1 means "given to this person even though their role lacks it".
--    granted=0 means "taken away from this person even though their role has it".
SELECT u.id, u.name, u.base_role, p.`key` AS permission,
       CASE o.granted WHEN 1 THEN 'GRANTED' ELSE 'REVOKED' END AS override
FROM   user_permission_overrides o
JOIN   users u       ON u.id = o.user_id
JOIN   permissions p ON p.id = o.permission_id
WHERE  p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS',
                   'FINANCIAL_VIEW_CLINIC_REPORTS',
                   'FINANCIAL_VIEW_DAILY_PL',
                   'FINANCIAL_RUN_PAYOUT')
ORDER  BY u.name, p.`key`;


-- 4. Sanity: what base_roles actually exist and how many users each has ------
--    (The old seed files mention ACCOUNTANT/NURSE/RECEPTIONIST, but the app was
--    collapsed to ADMIN/DOCTOR/STAFF — this confirms what is really in use.)
SELECT base_role, COUNT(*) AS users, SUM(is_active = 1) AS active
FROM   users
GROUP  BY base_role
ORDER  BY base_role;
