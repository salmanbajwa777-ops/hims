-- ============================================================================
-- DIAGNOSTIC (read-only) — why does Atif not have day-closing access?
-- (2026-07-24)  ·  Run in phpMyAdmin. Changes NOTHING. Each query prints a
-- labelled result so you can read the cause top-to-bottom.
--
-- The gate: shift_closing.php calls require_permission('RECEPTION_CLOSE_DAY')
-- with no role fallback. Effective access = role grant + per-user override,
-- where a per-user revoke (granted=0) BEATS the role grant. These queries expose
-- every layer.
-- ============================================================================

-- 0. Confirm which user "Atif" is (id, role, active flag). If more than one row,
--    note the id you care about and it's the one used implicitly below via name.
SELECT '0. WHO IS ATIF' AS report;
SELECT id, name, base_role, is_active
FROM users
WHERE name LIKE '%ATIF%';

-- 1. Does the RECEPTION_CLOSE_DAY permission row exist, and in which category?
SELECT '1. THE PERMISSION KEY' AS report;
SELECT id, `key`, label, category
FROM permissions
WHERE `key` = 'RECEPTION_CLOSE_DAY';

-- 2. Does Atif's ROLE grant it by default? (role_permissions for his base_role)
SELECT '2. ROLE-LEVEL GRANT FOR ATIFS ROLE' AS report;
SELECT u.name, u.base_role,
       CASE WHEN rp.permission_id IS NULL THEN 'NO  - role does not grant it'
            ELSE 'YES - role grants it' END AS role_grants_it
FROM users u
LEFT JOIN permissions p ON p.`key` = 'RECEPTION_CLOSE_DAY'
LEFT JOIN role_permissions rp
       ON rp.base_role = u.base_role AND rp.permission_id = p.id
WHERE u.name LIKE '%ATIF%';

-- 3. Does Atif have a PER-USER override on this key? granted=1 grants,
--    granted=0 REVOKES (and a revoke wins over the role grant).
SELECT '3. PER-USER OVERRIDE FOR ATIF' AS report;
SELECT u.name, o.granted,
       CASE o.granted WHEN 1 THEN 'per-user GRANT'
                      WHEN 0 THEN 'per-user REVOKE (blocks access even if role grants)'
       END AS override_meaning
FROM users u
JOIN permissions p ON p.`key` = 'RECEPTION_CLOSE_DAY'
JOIN user_permission_overrides o ON o.user_id = u.id AND o.permission_id = p.id
WHERE u.name LIKE '%ATIF%';
-- (No rows here = Atif has no personal override; access is decided by the role.)

-- 4. FINAL VERDICT — Atif's effective access, applying role + override exactly
--    as load_permissions() does in PHP.
SELECT '4. FINAL EFFECTIVE ACCESS' AS report;
SELECT u.name, u.base_role,
       CASE
         WHEN o.granted = 0 THEN 'DENIED - per-user revoke'
         WHEN o.granted = 1 THEN 'ALLOWED - per-user grant'
         WHEN rp.permission_id IS NOT NULL THEN 'ALLOWED - via role default'
         ELSE 'DENIED - no role grant and no per-user grant'
       END AS effective_access
FROM users u
LEFT JOIN permissions p ON p.`key` = 'RECEPTION_CLOSE_DAY'
LEFT JOIN role_permissions rp
       ON rp.base_role = u.base_role AND rp.permission_id = p.id
LEFT JOIN user_permission_overrides o
       ON o.user_id = u.id AND o.permission_id = p.id
WHERE u.name LIKE '%ATIF%';
