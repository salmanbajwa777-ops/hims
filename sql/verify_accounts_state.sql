-- ============================================================================
-- VERIFY: what is ACTUALLY live before the accounts/P&L build (2026-07-26)
--
-- READ-ONLY. Changes nothing. Run this in phpMyAdmin FIRST and read the
-- verdict column of each result set.
--
-- Why this file exists: "the migration was run" has been wrong three times on
-- this project, and the failures are SILENT — dashboard.php wraps the doctor
-- share query in a try/catch that returns 0 when consult_share_pct is missing,
-- so a missing column looks exactly like "no doctor earned anything." The DB
-- is the only authority. Do not skip this.
--
-- Run against the hims database (u402528120_hmis).
-- ============================================================================

-- ---- 1. THE CRITICAL ONE -----------------------------------------------
-- add_consult_revenue_share.sql — if these 3 columns are missing, EVERY
-- doctor share computes as Rs 0 and the P&L's largest deduction is silently
-- zero. Phase 1 must run that migration.
SELECT '1. consult revenue share (users)' AS check_name,
       COUNT(*) AS cols_found,
       CASE COUNT(*)
            WHEN 3 THEN 'LIVE — all 3 present, share math works'
            WHEN 0 THEN 'MISSING — run add_consult_revenue_share.sql (shares are all Rs 0 today)'
            ELSE 'PARTIAL — investigate before doing anything else'
       END AS verdict
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'users'
   AND column_name IN ('consult_share_pct', 'consult_has_tax', 'consult_tax_pct');

-- ---- 2. Expense columns the new build adds -----------------------------
-- period_month is NEW (nothing has added it yet) — expect 0.
SELECT '2. expenses.period_month' AS check_name,
       COUNT(*) AS cols_found,
       CASE COUNT(*) WHEN 0 THEN 'ABSENT — as expected, Phase 1 adds it'
                     ELSE 'ALREADY PRESENT — Phase 1 migration will skip it' END AS verdict
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND column_name = 'period_month';

-- ---- 3. expenses.source enum — must widen to allow BANK / OWNER --------
-- Salaries and rent are NOT paid from the counter drawer. If this still reads
-- only CASH_COUNTER, posting a Rs 400,000 salary would blow up the drawer tally.
SELECT '3. expenses.source enum' AS check_name,
       COALESCE(column_type, 'COLUMN MISSING') AS current_definition,
       CASE WHEN column_type LIKE '%BANK%' THEN 'ALREADY WIDENED'
            WHEN column_type IS NULL       THEN 'expenses table missing — run add_expenses.sql first'
            ELSE 'NEEDS WIDENING — Phase 1 adds BANK + OWNER' END AS verdict
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses' AND column_name = 'source';

-- ---- 4. Does the expenses module exist at all? -------------------------
-- add_expenses.sql + add_expense_approvals.sql were both listed as "not yet
-- run" at one point. Everything in Phase 1 depends on these tables.
SELECT '4. expense tables' AS check_name,
       GROUP_CONCAT(table_name ORDER BY table_name) AS tables_found,
       CASE WHEN COUNT(*) >= 3 THEN 'OK'
            ELSE 'INCOMPLETE — run add_expenses.sql / add_expense_approvals.sql' END AS verdict
  FROM information_schema.tables
 WHERE table_schema = 'u402528120_hmis'
   AND table_name IN ('expenses', 'expense_categories', 'expense_sequences', 'clinic_settings');

-- ---- 5. Existing expense categories ------------------------------------
-- Phase 1 adds Salaries / Rent / Doctor Shares. This shows what is already
-- there so we do not create near-duplicates of a category you already use.
SELECT '5. current categories' AS check_name, id, name, shift_limit
  FROM `u402528120_hmis`.expense_categories ORDER BY name;

-- ---- 6. is_period_based flag (NEW) -------------------------------------
SELECT '6. expense_categories.is_period_based' AS check_name,
       COUNT(*) AS cols_found,
       CASE COUNT(*) WHEN 0 THEN 'ABSENT — as expected, Phase 1 adds it'
                     ELSE 'ALREADY PRESENT' END AS verdict
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expense_categories'
   AND column_name = 'is_period_based';

-- ---- 7. Revenue stream tables (for Phases 3-4) -------------------------
-- The income report has to union whichever of these actually exist.
SELECT '7. revenue tables' AS check_name,
       GROUP_CONCAT(table_name ORDER BY table_name) AS tables_found,
       CONCAT(COUNT(*), ' of 5 present') AS verdict
  FROM information_schema.tables
 WHERE table_schema = 'u402528120_hmis'
   AND table_name IN ('bills', 'admission_bills', 'er_bills', 'refunds', 'ipd_admissions');

-- ---- 8. Are the dead financial permission keys present? ----------------
-- Seeded and granted to MANAGER but checked by ZERO php files today.
-- Phases 2-5 finally wire them, so they must exist in the catalog.
SELECT '8. financial view perms' AS check_name,
       GROUP_CONCAT(`key` ORDER BY `key` SEPARATOR ', ') AS keys_found,
       CASE WHEN COUNT(*) = 4 THEN 'ALL PRESENT — reports can gate on these'
            ELSE CONCAT('ONLY ', COUNT(*), ' of 4 — Phase 2 must seed the rest') END AS verdict
  FROM `u402528120_hmis`.permissions
 WHERE `key` IN ('FINANCIAL_VIEW_CLINIC_REPORTS', 'FINANCIAL_VIEW_DAILY_PL',
                 'FINANCIAL_VIEW_ALL_COMMISSIONS', 'FINANCIAL_VIEW_INVOICES');

-- ---- 9. Live doctor share settings -------------------------------------
-- Only meaningful if check 1 says LIVE. If every share is 0.00, the payout
-- engine has nothing to pay even once the columns exist — the per-doctor
-- percentages still need entering on staff.php.
-- If check 1 returned MISSING, this query will error: that is expected, ignore it.
SELECT '9. doctor share settings' AS check_name,
       id, name, consult_share_pct, consult_has_tax, consult_tax_pct
  FROM `u402528120_hmis`.users
 WHERE base_role = 'DOCTOR' AND is_active = 1
 ORDER BY name;
