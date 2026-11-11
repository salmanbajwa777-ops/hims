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

## Generate Bill — DESIGN (draft 2026-11-11, from owner's sample: Premium Lab slip 2611-02385)
Honours all locked decisions above: patient pays the lab directly, nothing feeds clinic income / Day Closing /
doctor share / analytics, prices typed per bill, Metacare Diagnostics branding only.

### What the sample has -> what we do
| Sample element | Decision |
|---|---|
| Header: invoice no, Name, Ref By, Age/Gender, Contact, MR No | Same. From the order's patient (or walk-in fields); Ref By = `lab_orders.referred_by`. |
| Token #, Panel (blank in sample) | DROPPED. |
| Barcode of invoice no | DROPPED (consistent with "no QR code"). Say if you want it. |
| "Lab Copy" tag | Print page has a Lab copy / Patient copy toggle; default Patient copy. |
| Lines: Title, Process (Normal/Urgent), Reporting date/time, Sample no, Payable | All kept. Title = catalogue pick OR free text (catalogue has 3 tests, lab runs many). Sample no = typed text; lines sharing a value are one specimen. |
| Total / Adjustment / Net / Received / Balance | Total = sum of lines, Net = Total - Adjustment, Balance = Net - Received, all computed at print. Only Adjustment and Received are stored (typed facts). |
| Initial By + Init. Time | `created_by_id` name + `created_at`. |
| Note lines + IntegCubes footer | Fixed Metacare note lines + contact from config/brand.php. No third-party footer. |

### Data (new file sql/add_lab_billing.sql, run after add_lab_module.sql)
- `lab_invoices`: id, order_id (UNIQUE, one invoice per order), invoice_no (VARCHAR, YYMM-NNNNN), adjustment DECIMAL(10,2) DEFAULT 0,
  received DECIMAL(10,2) DEFAULT 0, created_by_id, created_at, updated_at. No total/net/balance columns.
- `lab_invoice_lines`: id, invoice_id, test_id NULL, title (snapshot), process ENUM('Normal','Urgent'), reporting_at DATETIME NULL,
  sample_no VARCHAR(30) NULL, amount DECIMAL(10,2), sort_order.
- Invoice number: own counter in `lab_invoice_counters` (period 'YYMM', last_seq), incremented in the same transaction. Separate from `invoice_counters`.
- Lines are not linked to `lab_results` rows. Bill and report share only the order.
- Editing: invoice editable while open; every edit audit-logged. Delete = admin only, logged. (Same as other admin docs.)

### Pages
- `lab_bill.php`: list of orders with bill state; "Generate Bill" opens order (create order inline if none: patient search or walk-in).
  Tick/type lines, type amount, process, reporting time, sample no; adjustment + received; live totals; Save, then Print PDF (`lab_bill_print.php`).
- Reuses `lab_csrf()` and `lab_load_order()`. Admin-only gate unchanged.

### Open decisions (need owner)
1. Invoice no format: monthly reset (YYMM-00001) as I assumed, or running number (sample's 02385 looks running)?
2. Is "Urgent" a real process type for Metacare, and does it change price? (Price is typed, so it is a label only.)
3. Can an invoice be reprinted after edit, or freeze at first print? Recommend: editable, reprint shows current values, edits audit-logged.
4. Partial payment later: is one `received` figure enough, or do you want a payments list (date + amount)? Recommend single figure.

### Clarification 2026-11-11 (owner)
- The clinic charges the patient and issues the invoice ON BEHALF of the lab. Payment is to the lab: it does NOT change clinic
  financials (income, doctor share, analytics stay untouched). No barcode on the bill (confirmed).
- PROPOSED, awaiting owner OK (amends decision 1): cash is physically collected by clinic staff, so it must be visible somewhere
  or the drawer will not reconcile. Add a lab-collections tally, separate from clinic financials:
  Collected (sum of `lab_invoices.received` per day/user) - Settled to lab (new admin-logged `lab_settlements`: date, amount, by)
  = Outstanding held for Metacare. Day Closing shows one informational line only, never counted as clinic income.

### Owner answers 2026-11-11 (LOCKED for Generate Bill)
1. Invoice no: monthly sequence, format `MCD-01-0001` (MCD-<month>-<seq>), resets each month. Counter keyed by (year, month) so the DB
   never collides; NOTE the printed number repeats a year later (Nov 2026 and Nov 2027 both start MCD-11-0001) -- owner to confirm OK.
2. Urgent = manually typed label for now; prices manually typed. A set-prices table comes LATER (add `lab_tests.price` then).
3. Invoices editable; reprint shows current values; edits audit-logged.
4. Single `received` figure; `Balance due` = Net - Received, printed when > 0 (partial payment case).
Still open: lab-collections tally (see Clarification above).
Next: mock_lab_bill.html -> owner approval -> sql/add_lab_billing.sql + lab_bill.php + lab_bill_print.php.
- Printed bill paper size: A5 (portrait assumed; owner to confirm). 3 test lines fit comfortably; ~12 lines max before it needs a second page.
