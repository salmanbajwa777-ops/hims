-- =============================================================================
-- STEP 1 — WHICH OF THE PENDING MIGRATIONS DO I ACTUALLY NEED TO RUN?
--
-- READ-ONLY. Nothing here writes. Run this FIRST, in phpMyAdmin, and keep the
-- result — it tells you exactly which parts of STEP2 you need and which you can
-- skip. Do not skip this step: "was it run?" has been answered from notes and
-- guessed wrong four separate times on this database, which is why the whole
-- audit was gated on asking the schema instead.
--
-- HOW TO READ THE RESULT
--   status = 'RUN THIS'   -> not applied. The matching section of STEP2 applies.
--   status = 'already ok' -> applied. SKIP that section of STEP2.
--
-- Rows needing action sort to the top.
--
-- Every table is FULLY QUALIFIED on purpose. A query against information_schema
-- switches phpMyAdmin's current-database context, so a following bare table name
-- dies with "#1109 Unknown table ... in information_schema".
--
-- If your database is not u402528120_hmis, find-and-replace that name below.
-- =============================================================================

SELECT
    CASE WHEN m.applied > 0 THEN 'already ok' ELSE 'RUN THIS' END AS status,
    m.step,
    m.migration,
    m.what_it_gives_you
FROM (

  -- ---------------------------------------------------------------------
  -- 2A — IPD advances. Blocks the advance ledger, discharge settlement and
  --      the excess-return receipt. Two sentinels: the table AND the two
  --      ipd_bills columns, because the file's own header says the ALTERs are
  --      run separately and one can land without the other.
  -- ---------------------------------------------------------------------
  SELECT '2A' AS step, 'ipd/add_ipd_advances.sql' AS migration,
         'IPD advance ledger + discharge settlement' AS what_it_gives_you,
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema='u402528120_hmis' AND table_name='ipd_advances') AS applied

  UNION ALL SELECT '2A', 'ipd/add_ipd_advances.sql (ipd_bills columns)',
         'advance_applied / advance_refunded on the IPD bill',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='ipd_bills'
        AND column_name IN ('advance_applied','advance_refunded'))

  -- ---------------------------------------------------------------------
  -- 2B — Notifications. The app bar renders a bell against this table.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2B', 'add_notifications.sql',
         'in-app notifications + the app-bar bell',
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema='u402528120_hmis' AND table_name='notifications')

  -- ---------------------------------------------------------------------
  -- 2C — Expense approvals. Until this runs there is NO approval flow, while
  --      an over-limit voucher still reduces expected_cash — so the cashier is
  --      short at every close with no line explaining it.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2C', 'add_expense_approvals.sql',
         'expense approval flow (magic-link decisions)',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='expenses'
        AND column_name='approval_status')

  -- ---------------------------------------------------------------------
  -- 2D — MANAGER role. Until this runs, promoting someone to MANAGER writes
  --      '' (MySQL coerces an out-of-ENUM value in non-strict mode) and
  --      silently strips every role default from that user.
  --
  --      NOTE: staff.php now reads this ENUM at runtime and only offers
  --      MANAGER when the column can store it, so the coercion hazard is
  --      already closed in code. Running this is what makes the role usable.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2D', 'add_manager_role.sql',
         'MANAGER as a real base_role value',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='users'
        AND column_name='base_role' AND column_type LIKE '%MANAGER%')

  -- ---------------------------------------------------------------------
  -- 2E — Lock the finance reports to ADMIN. This one is a GRANT change, not a
  --      schema change, so the sentinel is inverted: it counts NON-ADMIN roles
  --      that still hold a finance key. 0 = already locked.
  --
  --      While this is unapplied, any non-admin still holding
  --      FINANCIAL_VIEW_CLINIC_REPORTS can reach pnl_report.php by URL.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2E', 'lock_finance_reports_to_admin.sql',
         'P&L / Doctor Share / Tax Register limited to ADMIN',
    (SELECT CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END
       FROM u402528120_hmis.role_permissions rp
       JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
      WHERE p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_DAILY_PL',
                        'FINANCIAL_VIEW_CLINIC_REPORTS','FINANCIAL_RUN_PAYOUT')
        AND rp.base_role <> 'ADMIN')

  -- ---------------------------------------------------------------------
  -- 2F — Admission manual discount at discharge.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2F', 'add_admission_manual_discount.sql',
         'lump-sum Rs / % discount at discharge',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='admission_bills'
        AND column_name='manual_discount_amount')

  -- ---------------------------------------------------------------------
  -- 2G — Per-doctor invoice paper size.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2G', 'add_invoice_paper_size.sql',
         'per-doctor A5/A4 consultation slip',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='users'
        AND column_name='invoice_paper_size')

  -- ---------------------------------------------------------------------
  -- 2H / 2I — Procedure consent. Audited earlier in this project: the consent
  --      sheet only prints when these are applied, and it FAILS SILENTLY
  --      otherwise (every consent path is behind a schema probe), so a missing
  --      migration here looks exactly like "this procedure has no consent form".
  --      Order matters: add_procedure_consent MUST precede the CNIC file.
  -- ---------------------------------------------------------------------
  UNION ALL SELECT '2H', 'add_procedure_consent.sql',
         'per-procedure consent template + printed consent sheet',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='procedure_master'
        AND column_name='consent_template')

  UNION ALL SELECT '2I', 'add_consent_signer_cnic.sql  (needs 2H first)',
         'CNIC of the person signing the consent',
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='u402528120_hmis' AND table_name='dental_consents'
        AND column_name='signed_cnic')

) m
ORDER BY m.applied ASC, m.step;
