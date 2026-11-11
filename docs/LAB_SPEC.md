# Lab Bills & Results — SPEC (DRAFT, not approved)

Status: draft 2026-11-11. No code beyond the admin-only sidebar item + placeholder pages
(`lab_bill.php`, `lab_report.php`). Nothing below is built until approved + mock approved.

## Locked (from owner)
- Admin-only for now. Sidebar: Workspace > Lab Bills & Results > Generate Bill / Generate Report.
- Lab orders come from two routes: a doctor orders a test, OR admin creates an order manually.
- Tests are run by an **external lab** (the clinic does not run them in-house).
- **Generate Bill** produces a PDF invoice for the patient against the tests run.
  It feeds NOTHING: not clinic income, not Visit Log, not Day Closing, not doctor share, not analytics.
- **Generate Report** produces the patient's results printout PDF. Specimen info is added later.
- Results enter two ways: typed manually, or uploaded as a PDF from the lab.

## Proposed data model (all lowercase table names; hand-written SQL, no migration)
- `lab_tests` — catalogue: id, name, unit, ref_range_text, sort_order, active (NO price; prices typed per bill).
- `lab_orders` — id, patient_id (NULL for walk-in), walkin_name/age/sex/phone, ordered_by (doctor user id, NULL if manual),
  created_by (user id), status (ORDERED / RESULTED / BILLED), created_at.
- `lab_order_items` — id, order_id, test_id, test_name_snapshot, price_snapshot, result_value,
  result_flag (normal/high/low), result_notes.
- `lab_result_files` — id, order_id, file_path, uploaded_by, uploaded_at (uploaded lab PDFs).
- `lab_invoices` — id, order_id, invoice_no (own series, e.g. LB-000001), total, printed_at, created_by.
  Separate table on purpose so it can never be summed into income by accident.
- Specimen columns deferred ("later"); leave room on `lab_orders`.

## Pages
- Generate Bill: pick/search patient (or order), tick tests, type each price, Generate PDF.
  Lab name only, layout per owner's sample.
- Generate Report: pick order, enter values or attach lab PDF, Generate PDF printout.
- Doctor ordering UI (doctor sidebar) is OUT OF SCOPE for step 1; admin creates orders first.

## Decisions (owner, 2026-11-11)
1. Patient pays the **lab directly**. No link to clinic financials; no ledger, nothing in income/Day Closing/handover.
2. Patient may be an existing HIMS patient OR a typed walk-in (name, age, sex, phone). `lab_orders.patient_id` nullable + typed fields.
3. **Prices typed manually per bill.** No price column in the catalogue (`lab_tests` has no price).
4. **One** lab: **Metacare Diagnostics** (owner's own lab). Name/logo/contact live in config, not a per-order dropdown.
5. Bill + report PDFs show **only Metacare Diagnostics** (no clinic branding). Report layout follows the owner's sample
   (Premium Lab report used purely as a layout reference): header block (name, MR no, age/gender, referred by, specimen/report date),
   Test | Result | Unit | Reference range columns grouped by section (Hematology, Clinical Chemistry...), out-of-range values bold,
   note lines, footer with contact. NO QR code, NO pathologist/doctor block, NO "digitally verified" line, NO third-party branding.
6. Owner uploads the lab's reference-range PDF; Claude transcribes it into `lab_tests` (name, unit, ref range text,
   sort order) and writes a seed SQL file the owner runs. Result entry prefills ranges from the catalogue; per-result override allowed.
7. More roles later: add permission key `LAB_MANAGE` (seed SQL + sidebar `perm`), swapping the admin-only `roles` gate.
   Until then admin-only. Doctor ordering UI comes with that step.

## Waiting on owner
- Metacare logo + footer contact details; sample bill PDF.
- Decision: reference ranges per age band (children's clinic: CBC ranges in the sample are paediatric) or one range per test.
- Reference-range PDF.
Then: HTML mock of both pages + both PDFs -> approval -> `add_lab_module.sql` + `seed_lab_tests.sql` -> build.

## Update 2026-11-11
- Bill sample + bill page: LATER. Age-band ranges: LATER (one range per test for now, editable per result). No "Last Available" column.
- Seed catalogue for QA = the 3 uploaded sample tests (CRP, CBC, Dengue NS-1); owner adds the full list later.
- Mock: mock_lab_report.html (awaiting approval). Logo: assets/img/metacare_logo.png.
- Footer contact = clinic's phone + address, read from config/brand.php at build time (no email, no hardcoding).
- BUILT 2026-11-11 (untested, no PHP on dev machine): sql/add_lab_module.sql, sql/seed_lab_tests.sql, config/lab.php,
  lab_report.php (list/enter/edit/attach PDF), lab_report_print.php, lab_file.php. Generate Bill still a placeholder.
  Deviation from draft: lab_orders has no status/external_lab_name; lab_results is a per-parameter snapshot table
  (replaces lab_order_items); lab_invoices deferred with the bill.
