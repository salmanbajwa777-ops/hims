-- Unify the two admit permission keys (2026-07-24).
--
-- Background: the Phase-4 overhaul added ADMISSION_ADMIT_PATIENT to replace the
-- legacy RECEPTION_ADMIT_PATIENTS, but the swap was only half done:
--   * The UI (patients.php, receptionist.php, doctor.php) shows the Admit button
--     on EITHER key (OR).
--   * The action handler (config/admission_actions.php) accepted ONLY the new
--     key -> a receptionist (e.g. Zoya) who still had only the legacy key saw the
--     button but got "you don't have permission to admit."
--
-- config/admission_actions.php is now fixed to accept EITHER key. This migration
-- makes the DB side consistent too, so the two keys can't drift again:
--   A. Re-assert add_admit_permission.sql's role grants (idempotent).
--   B. Backfill ADMISSION_ADMIT_PATIENT to EVERY base_role that currently holds
--      the legacy RECEPTION_ADMIT_PATIENTS (covers any non-standard base_role).
--   C. Backfill it as a per-user grant to any user who has the legacy key via a
--      user_permission_overrides grant.
--
-- Idempotent: safe to paste into phpMyAdmin repeatedly.

-- ---------------------------------------------------------------------------
-- DIAGNOSE FIRST (read-only). Run this block alone to see why a user is blocked.
-- Replace 'zoya' with the receptionist's login/name column as used in `users`.
-- ---------------------------------------------------------------------------
-- SELECT u.id, u.name, u.base_role,
--        MAX(p.`key` = 'ADMISSION_ADMIT_PATIENT')  AS has_new_via_role,
--        MAX(p.`key` = 'RECEPTION_ADMIT_PATIENTS')  AS has_old_via_role
-- FROM users u
-- LEFT JOIN role_permissions rp ON rp.base_role = u.base_role
-- LEFT JOIN permissions p ON p.id = rp.permission_id
-- WHERE u.name LIKE '%zoya%'
-- GROUP BY u.id;
--
-- -- Per-user overrides for the same user (granted=1 add, granted=0 revoke):
-- SELECT u.name, p.`key`, o.granted
-- FROM user_permission_overrides o
-- JOIN users u ON u.id = o.user_id
-- JOIN permissions p ON p.id = o.permission_id
-- WHERE u.name LIKE '%zoya%' AND p.`key` IN ('ADMISSION_ADMIT_PATIENT','RECEPTION_ADMIT_PATIENTS');

-- ---------------------------------------------------------------------------
-- A. Make sure the new permission row exists (in case add_admit_permission.sql
--    was never run on this DB).
-- ---------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'ADMISSION_ADMIT_PATIENT' AS `key`,
           'Admit a patient to ER / short-stay (doctor or reception)' AS label,
           'clinical' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- ---------------------------------------------------------------------------
-- B. Grant ADMISSION_ADMIT_PATIENT to every base_role that currently holds the
--    legacy RECEPTION_ADMIT_PATIENTS. This is the key line that unblocks Zoya
--    even if her base_role is not the literal 'RECEPTIONIST' the other migration
--    hard-coded.
-- ---------------------------------------------------------------------------
INSERT INTO role_permissions (base_role, permission_id)
SELECT DISTINCT rp_old.base_role, p_new.id
FROM role_permissions rp_old
JOIN permissions p_old ON p_old.id = rp_old.permission_id AND p_old.`key` = 'RECEPTION_ADMIT_PATIENTS'
JOIN permissions p_new ON p_new.`key` = 'ADMISSION_ADMIT_PATIENT'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp2
    WHERE rp2.base_role = rp_old.base_role AND rp2.permission_id = p_new.id
);

-- ---------------------------------------------------------------------------
-- C. Mirror per-user GRANT overrides of the legacy key onto the new key, so a
--    user granted admit individually (not via role) also keeps working. Revokes
--    (granted=0) are deliberately NOT mirrored.
-- ---------------------------------------------------------------------------
INSERT INTO user_permission_overrides (user_id, permission_id, granted)
SELECT o.user_id, p_new.id, 1
FROM user_permission_overrides o
JOIN permissions p_old ON p_old.id = o.permission_id AND p_old.`key` = 'RECEPTION_ADMIT_PATIENTS'
JOIN permissions p_new ON p_new.`key` = 'ADMISSION_ADMIT_PATIENT'
WHERE o.granted = 1
  AND NOT EXISTS (
      SELECT 1 FROM user_permission_overrides o2
      WHERE o2.user_id = o.user_id AND o2.permission_id = p_new.id
  );

-- After running, affected users must re-login (permissions are cached in the
-- session at login) OR just hit any page — refresh_session_permissions() reloads
-- them on every request, so a page refresh is enough.
