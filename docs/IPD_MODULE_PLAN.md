# IPD / In-Door Admission Module — Phased Implementation Plan

> Status: DRAFT for approval (2026-07-25). No code until sign-off.
> Brand-new module, fully separate from the ER short-stay `admission_*` tables/pages (read-only references only).

## Conventions to match (verified against live code)
- Page bootstrap: auth.php → require_login() → db.php → permissions.php → billing.php → refresh_session_permissions(). Timezone pinned PKT inside permissions.php, so every NOW() is PKT.
- Access = permission-driven only (`has_permission()` / `require_permission()`), never role. Base roles ADMIN/DOCTOR/STAFF.
- `$hideMoney = ($baseRole === 'DOCTOR')` — doctors see clinical, not charges.
- Migrations: CREATE TABLE IF NOT EXISTS; ADD COLUMN via stored-proc probing information_schema; seeds via INSERT..WHERE NOT EXISTS; permissions seeded+granted the same way (add_admissions.sql:285-312).
- Layout: $pageTitle/$headExtra heredoc → head.php → $navActive → sidebar.php (doctor path short-circuits to doctor_sidebar.php) → .content → close.
- Dates d/m; audit_logs on every mutation; outbound integrations post-commit, wrapped, best-effort.
- Separate invoice series precedent: generate_admission_invoice_number() ("A" prefix, own counter, GREATEST-of-counter-and-real-max).

## Discharge summary — MANUAL (2026-07-25 decision: AI dropped)
- NO Claude/Anthropic integration. The author (doctor) writes the discharge summary by hand in a plain form.
- Still pre-fill Final Diagnosis from the latest ward-round note's Primary Diagnosis (that carry-forward chain already exists — free), and optionally show the ward-round notes on-screen for reference while writing. But no API call, no ai_config.php, no cURL client, no fallback-draft logic.
- source column collapses to MANUAL only (keep the column for future-proofing but only value used is 'MANUAL').
- AI drafting is deferred to a later version (was in the original spec's deferred list anyway).

## Phase order
P1 admit+schema → P2 ward-round note → P3 vitals/care/handover → P4 discharge+summary+billing. (P2 and P3 both only need P1; P4 needs all.)

---
## PHASE 1 — Admit + room allotment + schema foundation
Goal: receptionist admits an in-patient, types room 1–4, picks ward+consultant+provisional diagnosis; ward list + stay page render.

Migration `sql/ipd/add_ipd_admissions.sql`:
- `ipd_ward_rates`: id, ward UNIQUE, per_day_rate, consultant_visit_fee, is_enabled, updated_by_id, updated_at. Seed General/Private/ICU @ 0.
- `ipd_admissions`: id, visit_id UNIQUE FK visits, ward, room_no TINYINT (1–4, app-clamped, NO bed-resource table), admitted_at, admitting_consultant_id FK users NULL, admitting_consultant_manual, provisional_diagnosis VARCHAR(500), admitted_by_id, admitted_by_role, discharged_at, discharge_finalized_by_id, discharge_finalized_at, status ENUM('ACTIVE','DISCHARGE_IN_PROGRESS','DISCHARGED'), timestamps, idx_ipd_status.
- Perms: IPD_ADMIT_PATIENT (ADMIN,STAFF), IPD_VIEW_WARD (ADMIN,STAFF,DOCTOR), IPD_MANAGE_WARD_RATES (ADMIN).

PHP: config/ipd_actions.php (handle_ipd_admit), partials/ipd_admit_modal.php, ipd_admissions.php (ward list), ipd_admission.php (stay page hub — header only in P1), ipd_ward_rates.php (admin).
Sidebar: add In-Door item (perm IPD_VIEW_WARD) + ward-rates admin link. quick_header In-Door button. Admit launchers on patients.php + reception queue (separate from ER, don't touch ER modal).

Open Qs: (1) new visits.disposition value IN_DOOR? [rec: yes]. (2) age format. (3) admitting consultant required at admit or later?

---
## PHASE 2 — Consultant ward-round note (ipd_doctor_visits)
Goal: immutable note, 3-tier diagnosis carry-forward, required Progress, latest-vitals display, min-to-save gate. Author = logged-in covering doctor.

Migration `sql/ipd/add_ipd_doctor_visits.sql`:
- `ipd_doctor_visits`: id, admission_id FK, doctor_id (author), visited_at, hospital_day, primary_diagnosis VARCHAR(500) NOT NULL, secondary_diagnosis, active_complaints, progress ENUM('IMPROVING','STABLE','SLOW','DETERIORATING','CRITICAL') NOT NULL, positive_findings/investigation_review/clinical_assessment/management_plan/family_counselling TEXT, next_review ENUM('EVENING','TOMORROW_AM','TOMORROW_PM','AFTER_48H','PRN'), is_paid, visit_charge, entered_by_user_id, created_at, idx(admission_id,visited_at). Immutable in app code (INSERT-only).
- `ipd_care_events` (shared flow-sheet; created here or P3, idempotent): id, admission_id FK, event_type ENUM('DOCTOR_VISIT','NURSING_CARE','MEDICATION','OBSERVATION','HANDOVER','OTHER'), ref_table, ref_id, note, logged_by_id, logged_by_role, event_at, created_at.
- Perms: IPD_WRITE_WARD_ROUND (ADMIN,DOCTOR), IPD_VIEW_WARD_ROUNDS (ADMIN,DOCTOR,STAFF).

PHP: ipd_ward_round.php — auto header (Consultant = logged-in doctor; Hospital Day = DATEDIFF(NOW(),DATE(admitted_at))+1); 3-tier pre-fill (latest note → else provisional_diagnosis); Progress required single-select 🟢🟡🟠🔴⚫; latest vitals read-only + Refresh (try/catch tolerant pre-P3); free-text Investigation Review (labs panel dropped); Management Plan documentation-only; min-to-save = primary_diagnosis + progress + (assessment OR plan). On save: INSERT immutable row (visit_charge from ward consultant_visit_fee), is_paid=1 if first note of CURDATE() for admission else 0, INSERT ipd_care_events DOCTOR_VISIT, audit. Read-only timeline newest-first, corrections=new note. $hideMoney for doctors. Add In-Door item to doctor_sidebar.php.

Open Qs: (1) visit_charge per-ward vs flat [assumed per-ward]. (2) fee attributes to author [confirmed by design]. (3) DB immutability trigger or app-only [rec app-only].

---
## PHASE 3 — Nursing vitals + care flow-sheet + handovers
Migration `sql/ipd/add_ipd_vitals_care.sql`:
- `ipd_vitals`: column-for-column mirror of admission_vitals, FK ipd_admissions.
- `ipd_handovers`: clone of admission_handovers, FK ipd_admissions.
- ipd_care_events (if not from P2).
- Perms: IPD_RECORD_VITALS (ADMIN,STAFF,DOCTOR), IPD_LOG_CARE (ADMIN,STAFF), IPD_RECORD_HANDOVER (ADMIN,STAFF), IPD_VIEW_VITALS_HISTORY (ADMIN,STAFF,DOCTOR). (Do NOT reuse ER NURSING_* perms.)

PHP: extend ipd_admission.php with vitals capture+timeline, care flow-sheet (merged doctor+nursing events chrono), handover panel. Keep on stay page (hub).

Open Qs: (1) vitals cadence nudge interval (ER=30min; IPD usually q4-6h) or drop. (2) "currently responsible nurse" pointer or log-only [rec log-only]. (3) medication events free-text only [rec yes].

---
## PHASE 4 — Discharge + manual discharge summary + IPD billing
Migration `sql/ipd/add_ipd_billing_discharge.sql`:
- ipd_invoice_counters (yr,mo,next_seq).
- ipd_bills: "I" prefix, own series; admission_id UNIQUE; subtotal/discount/manual_discount/grand_total; status draft/finalized/paid; payment_method; paid_amount; write_off_amount; paid_at; paid_by_id; created/finalized/printed/voided cols. paid_by_id matters for shift tally.
- ipd_bill_items: item_kind ENUM('STAY','CONSULT_VISIT','SERVICE').
- ipd_services: mirror admission_services, FK ipd_admissions, reference shared er_services_master catalogue.
- ipd_discharge_summaries: admission_id UNIQUE, final_diagnosis, summary_text MEDIUMTEXT, status draft/finalized, written_by_id/finalized_by_id, timestamps. (source col optional, only 'MANUAL' used — no AI.)
- Perms: IPD_FINALIZE_PAID_VISITS, IPD_LOG_SERVICES, IPD_DISCHARGE_PATIENT, IPD_WRITE_SUMMARY (ADMIN,DOCTOR), IPD_FINALIZE_BILL, IPD_APPROVE_WRITEOFF (ADMIN).

Helpers config/ipd_billing.php (keep OUT of billing.php): generate_ipd_invoice_number ("I"), ipd_stay_days, recalc_ipd_bill_totals, ensure_ipd_bill (STAY=per_day_rate×days; CONSULT_VISIT=SUM visit_charge WHERE is_paid=1; SERVICE=billable ipd_services), ipd_void_bill optional.

Discharge summary = MANUAL (no AI, no config/ipd_ai.php, no cURL client). ipd_discharge_summary.php: plain textarea the author writes; Final Diagnosis pre-filled from latest ward-round note's Primary Diagnosis; ward-round notes shown on-screen for reference; save draft → finalize. Author-written, immutable-ish (finalize locks).

PHP pages: ipd_paid_visits.php (reception finalizes paid count, ENFORCE ≥1 paid/day, only is_paid mutable+audited), ipd_discharge_summary.php (write/edit/finalize manual summary), ipd_discharge.php (clone admission_discharge.php, ensure_ipd_bill, discounts, settle, write-off), ipd_invoice.php ("I" print), submit-discharge action on ipd_admission.php.

Notify/sheet: additive notify_ipd_* in notify.php; IPD sheet logic in NEW config/ipd_sheets.php (reuse shared sheet_send).

RUN-ORDER GOTCHAS:
- Migrations strictly P1→P2→P3→P4 (all FK ipd_admissions).
- **Cash-tally conflict:** day_cash_tally() in billing.php:499-511 sums bills+admission_bills. For IPD paid bills to count in a receptionist's shift close, it needs a third UNION summing ipd_bills — the ONE place "don't modify billing.php" collides with reality. Decide: (a) minimal guarded additive edit, or (b) IPD cash tracked separately/outside shift close.
- require_day_open() reused as-is to block settling on closed shift.

Open Qs: (1) per-day rule DATEDIFF+1 vs 24h blocks. (2) IPD cash into shift close? (3) summary write gating ADMIN+DOCTOR or reception too. (4) doctor can submit discharge or reception-only. (5) ipd_void_bill parity or defer.

## Cross-cutting
No ER file modified except shared nav (sidebar/quick_header additive) and conditionally day_cash_tally(). notify/sheets additive or own ipd_sheets.php. IPD_* perm prefix. All email/sheet post-commit wrapped best-effort. NO AI integration. $hideMoney on ipd_admission.php + ipd_ward_round.php.
