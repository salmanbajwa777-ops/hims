-- ============================================================================
-- COLLAPSE ROLES → three base roles: ADMIN · DOCTOR · STAFF   (2026-07-24)
--
-- Decision (user): stop role-juggling. Every non-doctor, non-admin worker becomes
-- STAFF; what they can DO comes entirely from permissions (role defaults + per-user
-- overrides), never from their role name. Menus and gates read permissions, not roles.
--
-- WHY THIS ORDER IS SAFE (grant-before-move, move-before-shrink):
--   Phase 1 widens the enum to hold BOTH old + new values (nobody affected).
--   Phase 2 copies every grant the four legacy roles hold onto STAFF, so STAFF's
--           default = the UNION of reception/nurse/manager/accountant. No user
--           loses a capability, because their access is reproduced before they move.
--   Phase 3 moves the users to STAFF (their per-user overrides are untouched — those
--           are keyed by user_id, not role, so Atif's day-closing grant survives).
--   Phase 4 shrinks the enum to the final three values, now that nothing uses the old.
--
-- Audit/history enums (admissions.admitted_by_role, admission_vitals.recorded_by_role,
-- the admission-log logged_by_role, writeoff approved_by_role) are handled in a
-- SEPARATE migration (add_staff_to_audit_role_enums.sql) — they record what happened
-- historically and must keep their old values; they only need STAFF *added*.
--
-- Idempotent where practical; the MODIFY statements are naturally re-runnable.
-- RUN THIS BEFORE deploying the code that assumes 3 roles.
-- ============================================================================

-- ---- Phase 1: widen both enums to hold old + new simultaneously ----
ALTER TABLE users
    MODIFY base_role ENUM('ADMIN','DOCTOR','MANAGER','ACCOUNTANT','NURSE','RECEPTIONIST','STAFF') NOT NULL;
ALTER TABLE role_permissions
    MODIFY base_role ENUM('ADMIN','DOCTOR','MANAGER','ACCOUNTANT','NURSE','RECEPTIONIST','STAFF') NOT NULL;

-- ---- Phase 2: fold the four legacy roles' grants into STAFF (union) ----
-- Every (permission_id) held by ANY of the legacy roles becomes a STAFF grant.
-- DISTINCT + NOT EXISTS makes it a clean union with no duplicates.
INSERT INTO role_permissions (base_role, permission_id)
SELECT DISTINCT 'STAFF', rp.permission_id
FROM role_permissions rp
WHERE rp.base_role IN ('MANAGER','ACCOUNTANT','NURSE','RECEPTIONIST')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp2
      WHERE rp2.base_role = 'STAFF' AND rp2.permission_id = rp.permission_id
  );

-- ---- Phase 3: move the people ----
UPDATE users
SET base_role = 'STAFF'
WHERE base_role IN ('MANAGER','ACCOUNTANT','NURSE','RECEPTIONIST');

-- Drop the now-orphaned legacy role_permissions rows (their grants already live on
-- STAFF from Phase 2). Leaving them is harmless but the enum-shrink in Phase 4 would
-- reject them, so clear them first.
DELETE FROM role_permissions WHERE base_role IN ('MANAGER','ACCOUNTANT','NURSE','RECEPTIONIST');

-- ---- Phase 4: shrink to the final three roles ----
ALTER TABLE users
    MODIFY base_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL;
ALTER TABLE role_permissions
    MODIFY base_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL;

-- ---- Verify (read-only) ----
-- Expect only ADMIN / DOCTOR / STAFF, and STAFF holding the union of old grants.
SELECT base_role, COUNT(*) AS users FROM users GROUP BY base_role;
SELECT base_role, COUNT(*) AS grants FROM role_permissions GROUP BY base_role;
