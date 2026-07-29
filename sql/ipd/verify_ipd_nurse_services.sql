-- =============================================================================
-- VERIFY: sql/ipd/add_ipd_nurse_services.sql
--
-- Read-only. Reports whether each of that migration's three effects is present.
-- Every row comes back APPLIED or MISSING — no row is ever silently absent, so
-- an empty-looking result cannot be mistaken for a pass.
--
-- Run the whole file in phpMyAdmin (SQL tab) against u402528120_hmis.
-- Expected when fully applied: 3 rows, all APPLIED.
--
-- If any row says MISSING, run sql/ipd/add_ipd_nurse_services.sql. It is
-- idempotent (every step is guarded), so re-running a partially-applied
-- migration is safe.
-- =============================================================================

SELECT '1. permission IPD_REMOVE_SERVICE' AS check_name,
       CASE WHEN COUNT(*) > 0 THEN 'APPLIED' ELSE '** MISSING **' END AS result,
       CASE WHEN COUNT(*) > 0
            THEN CONCAT('id=', MAX(id), '  category=', MAX(category))
            ELSE 'admin sees no Remove button on a logged IPD service'
       END AS detail
FROM permissions
WHERE `key` = 'IPD_REMOVE_SERVICE'

UNION ALL

-- The grant matters independently of the permission row: load_permissions() has
-- NO admin short-circuit, so ADMIN holds a key only because role_permissions
-- genuinely carries an ADMIN row for it. Permission present + grant missing =
-- the button renders and then 403s.
SELECT '2. ADMIN grant for that permission' AS check_name,
       CASE WHEN COUNT(*) > 0 THEN 'APPLIED' ELSE '** MISSING **' END AS result,
       CASE WHEN COUNT(*) > 0
            THEN 'role_permissions has the ADMIN row'
            ELSE 'admin would get a 403 on the Remove action'
       END AS detail
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.`key` = 'IPD_REMOVE_SERVICE'
  AND rp.base_role = 'ADMIN'

UNION ALL

-- Composite index read by the bedside panel and the stay report, both of which
-- pull ipd_services for one admission ordered by logged_at. Absence is a
-- performance issue only — nothing errors without it.
SELECT '3. index idx_ipd_service_adm_time' AS check_name,
       CASE WHEN COUNT(*) > 0 THEN 'APPLIED' ELSE '** MISSING **' END AS result,
       CASE WHEN COUNT(*) > 0
            THEN CONCAT('columns: ', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX))
            ELSE 'panel + stay report fall back to the admission-only index'
       END AS detail
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'u402528120_hmis'
  AND TABLE_NAME   = 'ipd_services'
  AND INDEX_NAME   = 'idx_ipd_service_adm_time';
