-- Business-day cutoff hour for shift closing (2026-07-24).
--
-- Problem: a receptionist working past midnight saw their shift's date flip to
-- the new calendar day, so day_cash_tally() (which keyed off DATE(paid_at)=today)
-- dropped every payment taken before midnight and started a near-empty new day.
-- They could no longer document the currency notes they were actually holding.
--
-- Fix: one business day now runs from `day_cutoff_hour`:00 to the same hour the
-- next calendar day. Before the cutoff hour we are still on the previous business
-- day. Default 04:00 (0-23). Admin can change it here; no deploy needed. See
-- config/billing.php day_cutoff_hour() / business_day() / business_day_window().
--
-- clinic_settings is created by add_shift_closings.sql / add_expenses.sql; this
-- migration only seeds the row (idempotent), so run those first if needed.

INSERT INTO clinic_settings (setting_key, setting_value)
SELECT * FROM (SELECT 'day_cutoff_hour' AS setting_key, '4' AS setting_value) AS seed
WHERE NOT EXISTS (SELECT 1 FROM clinic_settings s WHERE s.setting_key = 'day_cutoff_hour');

-- To change the cutoff (e.g. to 06:00) after seeding:
-- UPDATE clinic_settings SET setting_value = '6' WHERE setting_key = 'day_cutoff_hour';
