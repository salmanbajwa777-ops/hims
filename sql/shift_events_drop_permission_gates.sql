-- Follow-up to sql/add_shift_events.sql: My Patients / My Shift Log / Visit
-- Log are no longer gated by a revocable permission — code now checks
-- base_role directly (my_patients.php: DOCTOR, my_shift_log.php: STAFF,
-- visit_log.php: guard_admin.php), so a stray user_permission_overrides row
-- can no longer hide any of the three from someone who should see it by
-- role. This removes the now-unused permission rows and their role grants
-- (and any override that referenced them) so the admin Permissions screen
-- doesn't show a control that no longer does anything.
--
-- Idempotent: safe to re-run.

DELETE FROM user_permission_overrides
WHERE permission_id IN (
    SELECT id FROM permissions
    WHERE `key` IN ('DOCTOR_VIEW_OWN_VISITS', 'RECEPTION_VIEW_OWN_SHIFT_LOG', 'FINANCIAL_VIEW_VISIT_LOG')
);

DELETE FROM role_permissions
WHERE permission_id IN (
    SELECT id FROM permissions
    WHERE `key` IN ('DOCTOR_VIEW_OWN_VISITS', 'RECEPTION_VIEW_OWN_SHIFT_LOG', 'FINANCIAL_VIEW_VISIT_LOG')
);

DELETE FROM permissions
WHERE `key` IN ('DOCTOR_VIEW_OWN_VISITS', 'RECEPTION_VIEW_OWN_SHIFT_LOG', 'FINANCIAL_VIEW_VISIT_LOG');
