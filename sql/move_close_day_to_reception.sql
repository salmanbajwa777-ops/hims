-- ============================================================================
-- Move "Run the day closing & cash handover" into the Front Desk & Reception
-- group on the Permissions screen. (2026-07-24)
--
-- RECEPTION_CLOSE_DAY was seeded under the 'financial' category, so it showed
-- under "Money & Billing" even though preparing the shift closing is front-desk
-- work every receptionist does. This re-categorizes it to 'reception' so it sits
-- with Register patients / Bookings / Timings on permissions.php.
--
-- Grants are UNCHANGED: the key is already default-granted to the RECEPTIONIST
-- role (sql/add_shift_closings.sql) and ADMIN, and admins can still assign it to
-- any individual staff member via the per-person overrides on the Staff page.
-- This migration only moves the display bucket; it adds/removes no access.
--
-- The admin-side ADMIN_RECEIVE_HANDOVER stays under Money & Billing on purpose
-- (receiving cash is not front-desk work).
--
-- Idempotent: pure UPDATE. Safe to re-run.
-- ============================================================================

UPDATE permissions
SET category = 'reception'
WHERE `key` = 'RECEPTION_CLOSE_DAY';
