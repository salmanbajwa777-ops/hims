-- Phase 4 (billing/flow overhaul, 2026-07-24): a DOCTOR (not just reception) can
-- admit a patient, and admit is reachable from the all-patients list too.
--
-- The old gate RECEPTION_ADMIT_PATIENTS is a misnomer once doctors admit, so this
-- introduces a semantically-correct ADMISSION_ADMIT_PATIENT granted to
-- DOCTOR + RECEPTIONIST + ADMIN + MANAGER. Existing reception/admin/manager users
-- keep working; doctors gain the ability. (admitted_by_role enum already lists DOCTOR.)
--
-- Idempotent: INSERT ... WHERE NOT EXISTS per key/grant. Safe to re-run.

INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'ADMISSION_ADMIT_PATIENT' AS `key`,
           'Admit a patient to ER / short-stay (doctor or reception)' AS label,
           'clinical' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Grant to DOCTOR + RECEPTIONIST + ADMIN + MANAGER.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'DOCTOR' AS base_role
    UNION ALL SELECT 'RECEPTIONIST'
    UNION ALL SELECT 'ADMIN'
    UNION ALL SELECT 'MANAGER'
) r
JOIN permissions p ON p.`key` = 'ADMISSION_ADMIT_PATIENT'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
