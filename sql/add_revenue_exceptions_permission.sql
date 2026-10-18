-- ============================================================================
-- REVENUE EXCEPTIONS REPORT — new FINANCIAL_VIEW_REVENUE_EXCEPTIONS key
--
-- Opens exceptions_report.php (read-only review list of short-stay ER billing
-- exceptions: short payments, zero/discounted bills, unbilled admissions,
-- voids, late-admission candidates, removed services). ADMIN always passes the
-- page's gate and sees the link; this key lets a MANAGER (or any user granted it
-- on Staff & Doctors) see the sidebar link and open the page. Read-only: it
-- grants no ability to change any bill.
--
-- Idempotent, ADDITIVE. Safe to re-run. Run in phpMyAdmin BEFORE (or right
-- after) deploying exceptions_report.php; until it runs only ADMIN has access.
-- ============================================================================

INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_VIEW_REVENUE_EXCEPTIONS' AS `key`,
           'View revenue exceptions report (read-only)' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'ADMIN' AS base_role
    UNION ALL SELECT 'MANAGER'
) r
JOIN permissions p ON p.`key` = 'FINANCIAL_VIEW_REVENUE_EXCEPTIONS'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- ---- Verify (read-only) ----
SELECT rp.base_role, p.`key`, p.category
  FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE p.`key` = 'FINANCIAL_VIEW_REVENUE_EXCEPTIONS'
 ORDER BY rp.base_role;
