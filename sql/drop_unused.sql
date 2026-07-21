-- ============================================================================
-- Drop unused schema objects (audited 2026-07-21)
--
-- RUN THIS IN phpMyAdmin. Every statement below was verified against the PHP
-- source first: each dropped object has ZERO queries referencing it anywhere in
-- the codebase (the only textual hits were in code comments or unrelated English
-- prose like "the most common tasks").
--
-- >>> TAKE A DATABASE EXPORT BEFORE RUNNING. Dropping a table destroys its rows
-- >>> permanently; nothing here is reversible without that backup.
--
-- Section 3 is deliberately NOT executed by default — read the note there.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. Unused tables
-- ---------------------------------------------------------------------------

-- Never queried. Password resets are not implemented: staff.php only offers an
-- admin-set temporary password + users.must_change_password, which is a different
-- mechanism entirely. If a self-service "forgot password" email flow is built
-- later, recreate this table from sql/schema.sql at that time.
DROP TABLE IF EXISTS password_reset_tokens;

-- Never queried. Task management was scaffolded in schema.sql but no UI or
-- endpoint was ever built for it. sql/add_delete_cascades.sql also touches this
-- table, so it must be dropped AFTER that migration has been applied (it has).
DROP TABLE IF EXISTS tasks;

-- Never queried, and never seeded with anything: the tax settings it was meant to
-- hold were deleted by sql/run_now_billing_no_tax.sql, and patient invoices carry
-- no tax at all by design. config/billing.php hardcodes the zero values instead of
-- reading them back.
DROP TABLE IF EXISTS clinic_settings;


-- ---------------------------------------------------------------------------
-- 2. Unused column: patients.approx_age
--
-- The "Approx. Age" input was removed from the registration form on 2026-07-21,
-- so no new row can receive a value here. The three read paths that displayed it
-- for older patients (patients.php, doctor.php, receptionist.php) were removed in
-- the same change, so nothing references this column any more.
--
-- Confirmed by the user 2026-07-21: the database holds test data only — no real
-- patient has been registered yet — so existing approx_age values are disposable
-- and no backfill is needed. Patients with no dob now simply show "—" for age.
--
-- DEPLOY ORDER: push the PHP first (or together with this). Running this against
-- the OLD code breaks registration, because the old INSERT still names the column.
-- ---------------------------------------------------------------------------

ALTER TABLE patients DROP COLUMN approx_age;


-- ---------------------------------------------------------------------------
-- 3. NOT DROPPED — deliberately kept. Do not "clean these up".
-- ---------------------------------------------------------------------------
--
-- bills.sales_tax_percent / sales_tax_amount /
-- bills.consolidation_rate_percent / consolidation_amount
--     Always written as 0, never displayed. They LOOK dead, but config/billing.php
--     still writes them on every INSERT/UPDATE (config/billing.php:33,82), so
--     dropping them breaks checkout immediately. They also preserve the historical
--     record for any pre-2026-07-17 bill that was created with real tax applied.
--
-- patients.cnic / alt_phone / address
--     Written on every registration (patients.php:133) but no form field currently
--     collects cnic/alt_phone, so they always store NULL. Kept because the INSERT
--     references them by name — dropping them is a code change, and these are
--     expected to gain UI fields rather than be removed.
--
-- audit_logs, user_permission_overrides, invoice_sequences, refund_sequences,
-- mrn_counters, visit_queue_counters, staff_documents
--     All actively queried. The low raw grep counts on the sequence/counter tables
--     are just because each is touched by exactly one atomic upsert.


-- ---------------------------------------------------------------------------
-- 4. Verify
-- ---------------------------------------------------------------------------
-- SHOW TABLES;
