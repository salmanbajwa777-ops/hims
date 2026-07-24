-- ============================================================================
-- Add 'STAFF' to the audit/history role enums.   (2026-07-24)
-- Companion to collapse_roles_to_staff.sql — run EITHER order, but both before
-- deploying the 3-role code.
--
-- These columns record WHO (by role) performed an action at the time. They are
-- history, so their existing values (NURSE/RECEPTIONIST/MANAGER/…) must stay.
-- But after the collapse, live inserts will stamp 'STAFF' (the actor's new
-- base_role), so STAFF must be a permitted value. We therefore ADD 'STAFF' and
-- keep every legacy value — additive only, no data rewritten.
--
-- Idempotent: re-running just re-asserts the same widened enum.
-- ============================================================================

-- admissions.admitted_by_role  (was RECEPTIONIST,ADMIN,MANAGER,DOCTOR)
ALTER TABLE admissions
    MODIFY admitted_by_role ENUM('RECEPTIONIST','ADMIN','MANAGER','DOCTOR','NURSE','STAFF') NOT NULL;

-- admission_vitals.recorded_by_role  (was NURSE,DOCTOR,ADMIN,MANAGER,RECEPTIONIST)
ALTER TABLE admission_vitals
    MODIFY recorded_by_role ENUM('NURSE','DOCTOR','ADMIN','MANAGER','RECEPTIONIST','STAFF') NOT NULL;

-- admission_services.logged_by_role  (was NURSE,RECEPTIONIST,ADMIN,MANAGER)
-- This is the chargeable-events log (a service logged against a stay).
ALTER TABLE admission_services
    MODIFY logged_by_role ENUM('NURSE','RECEPTIONIST','ADMIN','MANAGER','DOCTOR','STAFF') NOT NULL;

-- writeoff approved_by_role  (was ADMIN,MANAGER)
ALTER TABLE admission_writeoffs
    MODIFY approved_by_role ENUM('ADMIN','MANAGER','STAFF') NOT NULL;
