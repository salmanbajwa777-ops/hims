-- =============================================================================
-- WHICH MIGRATIONS ARE ACTUALLY APPLIED?  (read-only audit)
--
-- This project has NO migrations-tracking table, so "was it run?" has been
-- answered from stale notes and guessed wrong four separate times. This is the
-- authority: it probes the live schema for one SENTINEL object per migration —
-- the object that would only exist if that file had run.
--
-- applied = 1 -> that migration ran.  applied = 0 -> it did NOT.
-- Missing ones sort to the top.
--
-- Nothing here writes. Every table is FULLY QUALIFIED on purpose: a query
-- against information_schema switches phpMyAdmin's current-database context, so
-- a following bare table name dies with
-- "#1109 Unknown table ... in information_schema".
--
-- NOT auditable, deliberately omitted (they only insert/update rows that later
-- files overwrite, so no sentinel can distinguish them):
--   rbac_overhaul_2_grants.sql, rbac_overhaul_3_categories.sql,
--   fix_admit_permission_unify.sql, and the one-off data fixes (fix_*.sql,
--   uppercase_existing_names.sql, run_now_handover_fix.sql).
-- Note rbac_overhaul_3's UPDATEs SILENTLY FAILED on live (category was still an
-- ENUM); rbac_overhaul_4 is what actually fixed it, so audit step 4, not 3.
--
-- Near-duplicate pairs — if the sentinel is 1 you cannot tell which of the two
-- applied it, and per their own headers it was the run_now_* one:
--   add_void_actions.sql   vs run_now_void_columns.sql   (add_void failed #1044)
--   add_billing.sql        vs run_now_billing_no_tax.sql
--
-- Last full run: 2026-07-27 — everything applied EXCEPT add_expense_over_limit,
-- which used a DELIMITER-guarded CREATE PROCEDURE the Hostinger user is denied
-- (#1044) and so could never have run. Rewritten flat and applied.
-- =============================================================================

SELECT m.migration, m.why, m.applied FROM (
  SELECT 'add_consult_revenue_share' AS migration, 'doctor consult share %' AS why,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='users' AND column_name='consult_tax_pct') AS applied
  UNION ALL SELECT 'add_doctor_weekly_schedule','my_schedule.php weekly template',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='doctor_weekly_schedule')
  UNION ALL SELECT 'add_procedure_bills','procedure billing',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='procedure_bills')
  UNION ALL SELECT 'add_procedure_disposables','supplies cost',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='procedure_bill_items' AND column_name='disposables_cost')
  UNION ALL SELECT 'add_expense_over_limit','over-limit notes',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='expenses' AND column_name='limit_note')
  UNION ALL SELECT 'add_doctor_payouts','payout engine + tax register',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='doctor_payout_lines')
  UNION ALL SELECT 'add_accounts_phase1','disbursement categories',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='expense_categories' AND column_name='is_disbursement')
  UNION ALL SELECT 'add_monthly_closings','month-end freeze',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='monthly_closings')
  UNION ALL SELECT 'add_invoice_paper_size','per-doctor A4/A5 slip',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='users' AND column_name='invoice_paper_size')
  UNION ALL SELECT 'add_refund_paid_out_by','refund hits payer drawer',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='refunds' AND column_name='paid_out_by_id')
  UNION ALL SELECT 'add_admin_late_close','admin close-on-behalf',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='shift_closings' AND column_name='closed_by_admin_id')
  UNION ALL SELECT 'add_er_bills','ER walk-in bills',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='er_bill_items')
  UNION ALL SELECT 'add_admission_manual_discount','discharge lump-sum discount',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='admission_bills' AND column_name='manual_discount_by_id')
  UNION ALL SELECT 'add_void_actions/run_now_void_columns','soft-void',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='refunds' AND column_name='voided_at')
  UNION ALL SELECT 'add_bookings','appointments',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='visits' AND column_name='booking_id')
  UNION ALL SELECT 'add_shift_closings','day closing',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='shift_closing_denominations')
  UNION ALL SELECT 'add_per_user_closings','per-cashier closings',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='shift_closing_edits')
  UNION ALL SELECT 'add_closing_expenses','expenses in the tally',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='shift_closings' AND column_name='expense_total')
  UNION ALL SELECT 'add_expenses','petty cash',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='expense_sequences')
  UNION ALL SELECT 'add_expense_approvals','magic-link approvals',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='expense_approval_tokens')
  UNION ALL SELECT 'add_expense_paid_to_doctor','doctor disbursements',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='expense_categories' AND column_name='needs_doctor')
  UNION ALL SELECT 'add_discount_categories','Family/Charity/Loyalty',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='discount_categories')
  UNION ALL SELECT 'add_room_stay_discount','room-stay discount %',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='discount_categories' AND column_name='room_stay_pct')
  UNION ALL SELECT 'add_revisit_billing','revisit fee engine',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='doctor_consult_types' AND column_name='is_revisit_eligible')
  UNION ALL SELECT 'add_procedure_master','procedure catalogue',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='doctor_procedures')
  UNION ALL SELECT 'add_doctor_timings_session2','2nd daily session',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='doctor_day_timings' AND column_name='end_time_2')
  UNION ALL SELECT 'add_user_active_status','active/inactive users',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='users' AND column_name='is_active')
  UNION ALL SELECT 'add_discount_cap','per-user discount cap',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='users' AND column_name='max_discount_pct')
  UNION ALL SELECT 'add_email_log','email log',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='email_log')
  UNION ALL SELECT 'add_sheet_sync','Google Sheet log',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='sheet_sync_log')
  UNION ALL SELECT 'add_staff_documents','staff doc uploads',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='staff_documents')
  UNION ALL SELECT 'add_token_codes','per-doctor token codes',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='visit_queue_counters' AND column_name='token_session')
  UNION ALL SELECT 'add_service_clinical_note','ER service note',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='admission_services' AND column_name='clinical_note')
  UNION ALL SELECT 'add_admission_vitals','ER vitals',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='admission_vitals')
  UNION ALL SELECT 'rename_vitals_temp_to_fahrenheit','temp_f rename',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='admission_vitals' AND column_name='temp_f')
  UNION ALL SELECT 'add_admissions','short-stay admissions',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='admission_writeoffs')
  UNION ALL SELECT 'ipd/add_ipd_admissions','In-Door admit',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='ipd_admissions')
  UNION ALL SELECT 'ipd/add_ipd_doctor_visits','ward rounds',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='ipd_doctor_visits')
  UNION ALL SELECT 'ipd/add_ipd_vitals_care','IPD vitals/handovers',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='ipd_handovers')
  UNION ALL SELECT 'ipd/add_ipd_billing_discharge','IPD discharge + bill',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='ipd_discharge_summaries')
  UNION ALL SELECT 'ipd/add_ipd_nurse_services','nurse-logged IPD services',
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema='u402528120_hmis' AND table_name='ipd_services' AND index_name='idx_ipd_service_adm_time')
  UNION ALL SELECT 'rbac_overhaul_4_fix_category_enum','category ENUM->VARCHAR',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='permissions' AND column_name='category' AND data_type='varchar')
  UNION ALL SELECT 'collapse_roles_to_staff','3-role model (NURSE gone)',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='users' AND column_name='base_role' AND column_type NOT LIKE '%NURSE%')
  UNION ALL SELECT 'add_manager_role','MANAGER preset',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='users' AND column_name='base_role' AND column_type LIKE '%MANAGER%')
  UNION ALL SELECT 'add_waived_bill_status','waived bills',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='bills' AND column_name='status' AND column_type LIKE '%waived%')
  UNION ALL SELECT 'add_locations','city/area lookup',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='areas')
  UNION ALL SELECT 'add_mrn_monthly_counter','monthly MRN series',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='mrn_counters')
  UNION ALL SELECT 'add_invoice_counter','invoice counter',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='invoice_counters')
  UNION ALL SELECT 'add_refunds','refunds',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='refunds')
  UNION ALL SELECT 'add_doctor_consult_types','per-doctor consult types',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='doctor_consult_types')
  UNION ALL SELECT 'add_doctor_day_timings','per-day timings sheet',
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u402528120_hmis' AND table_name='doctor_day_timings')
  UNION ALL SELECT 'add_consult_status','visit consult status',
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='u402528120_hmis' AND table_name='visits' AND column_name='consult_status')
) m ORDER BY m.applied ASC, m.migration;
