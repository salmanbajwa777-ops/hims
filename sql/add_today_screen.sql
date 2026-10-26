-- Today screen (today.php): one live feed of everything happening in the clinic
-- on the current business day. Admin + Manager only.
--
-- Adds ONE permission, OVERSIGHT_VIEW_TODAY, granted to ADMIN and MANAGER.
-- No table changes: today.php reads shift_events, patients, ipd_admissions and
-- admissions as they already are.
--
-- Run in phpMyAdmin BEFORE deploying today.php + the sidebar change, otherwise
-- the Today link is hidden from everyone and the page answers 403.
-- Idempotent: safe to re-run.

INSERT INTO permissions (`key`, label, category)
SELECT 'OVERSIGHT_VIEW_TODAY', 'View the Today screen (live clinic-wide activity)', 'managerial'
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = 'OVERSIGHT_VIEW_TODAY');

INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'MANAGER') r
JOIN permissions p ON p.`key` = 'OVERSIGHT_VIEW_TODAY'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- Verify (read-only): expect two rows, ADMIN and MANAGER.
SELECT rp.base_role, p.`key`, p.category
  FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE p.`key` = 'OVERSIGHT_VIEW_TODAY';
