-- ============================================================================
-- PROCEDURE SHARES ON DOCTOR PAYOUTS  (2026-08-06)
--
-- THE BUG
--   payout_earning_lines() collects OPD consultations and IPD daily rounds, and
--   nothing else. doctor_share_statement.php has shown procedure earnings since
--   add_procedure_bills.sql went live, so a doctor could SEE what a procedure
--   earned them and never be PAID it — the payout silently settled short.
--
-- WHY A MIGRATION IS NEEDED FIRST
--   doctor_payout_lines.source_type is ENUM('OPD','IPD'). Inserting 'PROCEDURE'
--   into that column does not error on a default Hostinger connection — it
--   writes '' and MySQL raises a warning nobody reads. The UNIQUE key that stops
--   double-payment is (source_type, source_id, line_kind), so a whole month of
--   procedure lines would collapse onto '' and collide with each other. Deploy
--   THIS before the code that writes those lines.
--
-- GRAIN: one line per procedure_bill_items row, not per bill. Each item carries
--   its own doctor_share_pct / has_tax / tax_percent snapshot (a doctor can be
--   on 40% for one procedure and 60% for another), so a per-bill line could not
--   be split at any single rate. source_id is therefore procedure_bill_items.id.
--
-- NO STORED PROCEDURE: the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- Flat statements, fully qualified, re-runnable.
-- ============================================================================

-- ---- 1. Widen the ENUM ------------------------------------------------------
-- MODIFY rewrites the column definition, so the existing 'OPD'/'IPD' values must
-- be repeated or they would be dropped. Re-running this is a no-op.
ALTER TABLE `u402528120_hmis`.doctor_payout_lines
    MODIFY COLUMN source_type ENUM('OPD','IPD','PROCEDURE') NOT NULL;

-- ---- 2. Verify (read-only) --------------------------------------------------
-- 'PROCEDURE' must appear in COLUMN_TYPE below. If it does not, the ALTER did
-- not apply and the code must NOT be deployed — see the warning above.
SELECT column_name, column_type,
       CASE WHEN column_type LIKE '%PROCEDURE%' THEN 'READY'
            ELSE 'NOT APPLIED — do not deploy the payout code' END AS verdict
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis'
   AND table_name   = 'doctor_payout_lines'
   AND column_name  = 'source_type';

-- Any line that landed as '' before this migration was applied. Expect 0 rows.
-- A non-zero count means procedure lines were written against the old ENUM and
-- must be deleted from their DRAFT payout before it is settled.
SELECT COALESCE(COUNT(*), 0) AS blank_source_type_lines
  FROM `u402528120_hmis`.doctor_payout_lines
 WHERE source_type = '';
