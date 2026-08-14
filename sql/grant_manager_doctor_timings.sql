-- ============================================================================
-- GRANT MANAGER ACCESS TO DOCTOR TIMINGS (2026-08-13)
--
-- Admin can already set a doctor's standing weekly schedule and adjust
-- reception's per-day timings sheet. Manager gets the same reach: both the
-- day-sheet edit (doctor_timings.php, already gated on
-- RECEPTION_EDIT_DOCTOR_TIMINGS) and the weekly-default edit
-- (my_schedule.php, gated in code on base_role — see
-- widen_doctor_schedule_manager_access companion code change) key off this
-- ONE permission, so a single grant covers both surfaces.
--
-- add_manager_role.sql must be applied first (adds the MANAGER enum value).
-- Idempotent — NOT EXISTS guarded, safe to re-run.
-- ============================================================================

INSERT INTO role_permissions (base_role, permission_id)
SELECT 'MANAGER', p.id
FROM permissions p
WHERE p.`key` = 'RECEPTION_EDIT_DOCTOR_TIMINGS'
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = 'MANAGER' AND rp.permission_id = p.id
);

-- ---- Verify (read-only) ----
SELECT p.`key`, p.category
  FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE rp.base_role = 'MANAGER' AND p.`key` = 'RECEPTION_EDIT_DOCTOR_TIMINGS';
