-- ============================================================================
-- FIX — grant day-closing access to Atif. (2026-07-24)
-- Run ONLY after reading diag_atif_close_day.sql. Idempotent; safe to re-run.
--
-- This writes a PER-USER GRANT (granted=1) on RECEPTION_CLOSE_DAY for Atif.
-- It covers BOTH causes the diagnostic can show:
--   * no grant at all  -> inserts the grant
--   * an existing REVOKE (granted=0) -> flips it to 1 (grant wins)
-- Access takes effect on Atif's next page load (permissions reload every request).
--
-- NOTE: if the diagnostic shows more than one user matching "ATIF", replace the
-- name filter with the exact user id you want, e.g. WHERE u.id = 123.
-- ============================================================================

-- Grant (insert if absent, or flip a revoke to a grant if present).
INSERT INTO user_permission_overrides (user_id, permission_id, granted)
SELECT u.id, p.id, 1
FROM users u
JOIN permissions p ON p.`key` = 'RECEPTION_CLOSE_DAY'
WHERE u.name LIKE '%ATIF%'
ON DUPLICATE KEY UPDATE granted = 1;

-- Confirm it took: should read "ALLOWED - per-user grant".
SELECT u.name, u.base_role, o.granted,
       CASE WHEN o.granted = 1 THEN 'ALLOWED - per-user grant' ELSE 'still blocked' END AS effective_access
FROM users u
JOIN permissions p ON p.`key` = 'RECEPTION_CLOSE_DAY'
JOIN user_permission_overrides o ON o.user_id = u.id AND o.permission_id = p.id
WHERE u.name LIKE '%ATIF%';
