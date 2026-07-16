# HMIS Build Plan (PHP / MySQL — Hostinger)

**Supersedes:** `HMIS-COMPLETE.md`'s architecture section (Next.js/Prisma/EC2). That stack was
evaluated and explicitly rejected on 2026-07-15 in favor of PHP/MySQL on Hostinger, matching the
VacEmp project. **The functional spec in `HMIS-COMPLETE.md` — roles, patient journey, procedures,
billing, commissions, QA — is still the target.** Only the implementation layer changes:

| Layer | HMIS-COMPLETE.md | This plan |
|---|---|---|
| Backend | Next.js routes/server actions | Plain PHP pages (`feature.php`), one file per feature like `staff.php` |
| ORM/DB | Prisma + PostgreSQL | Raw PDO + MySQL (`sql/*.sql` migration files) |
| Deploy | GitHub Actions → AWS EC2 | GitHub Actions → FTP → Hostinger shared hosting (already wired, `.github/workflows/deploy.yml`) |
| Auth | NextAuth/session lib | Custom `config/auth.php` + PHP `$_SESSION` (already built) |

---

## 1. Actual current state (2026-07-17)

Live on `hims.babymedics.com`:

- `index.php`, `config/auth.php`, `config/guard_admin.php`, `logout.php`, `change-password.php`
- `dashboard.php` — admin landing page; `receptionist.php` — receptionist landing page
- `staff.php` — add/edit/list staff & doctors (grouped Doctors/Staff, alphabetical, edit panel
  with document upload, per-user permission overrides, **doctor consultation types + fees editor,
  per-person discount cap field, admin sets password directly (no auto-gen/email — no SMTP
  infra exists), hard-delete staff with confirm — added 2026-07-17**)
- `permissions.php` — role-default permission grid
- `document.php` — streams a single uploaded staff document by id
- `patients.php` — **added 2026-07-17.** Search (name/phone/father name/MRN) + full registration
  panel: identity, contact/city/area (with reception quick-add-area flow), and consultation
  (doctor → consultation type → fee dependency chain, discount input capped client-side against
  `users.max_discount_pct`, server re-validates). Submitting inserts `patients` + `visits` in one
  transaction, assigns an MRN and a per-doctor daily queue token, and shows a queue confirmation
  screen. Gated by `RECEPTION_REGISTER_PATIENTS`; admin-only hard-delete on each search result row
  (removes the patient's full visit history with them — confirm-gated).
- `locations.php` — **added 2026-07-17,** admin-only. Two-pane city/area manager (add/remove) plus
  a pending-review queue for reception-added areas (approve as-is, rename, or merge into an
  existing area).
- DB tables live in `sql/schema.sql`, `sql/add_staff_documents.sql`, and (2026-07-17)
  `sql/add_locations.sql`, `sql/add_doctor_consult_types.sql`, `sql/add_patients.sql`,
  `sql/add_discount_cap.sql` (**applied on the live DB**), plus `sql/add_delete_cascades.sql`
  (**not yet applied — needed before the delete-staff/delete-patient buttons will work; loosens
  several FKs from RESTRICT to CASCADE/SET NULL so a `DELETE FROM users`/`patients` doesn't error
  out**). Per the standing rule: never push schema-dependent PHP before its migration has run —
  the delete buttons are already deployed in code, so they'll 500/error until this migration is
  applied.
  - `users` (id, name, email, phone, password, base_role enum, must_change_password,
    `max_discount_pct` NEW, created_at)
  - `password_reset_tokens`
  - `permissions`, `role_permissions`, `user_permission_overrides`
  - `tasks` — scaffolded, no UI yet, not part of `HMIS-COMPLETE.md` spec (separate ask, keep it)
  - `audit_logs`
  - `staff_documents`
  - `cities`, `areas` (`status`, `added_by_id` for the reception-quick-add/admin-review flow) NEW
  - `doctor_consult_types` (per-doctor label/fee/is_default, managed on `staff.php`) NEW
  - `patients`, `visits` (visit carries `fee`, `discount_pct`, `discount_applied_by_id`,
    `token_no`, `payment_mode`) NEW

**Delete vs. deactivate (2026-07-17):** hard-delete on `staff.php`/`patients.php` is explicitly
for cleaning up test data while nothing is live yet. Once real usage starts, the ask is to add an
active/inactive toggle for staff instead of deleting them (preserves their history/attribution
cleanly rather than nulling it out) — **not built yet**, deletion stays as the only option for now.
When that toggle is added, `has_permission()`/login should probably also block inactive users from
signing in.

Still nothing for: `vitals`, `procedures`, `bills`, `beds`, `rate_master`,
`doctor_financial_terms`, `tax_config`, `staff_commission_config`, `short_stay_*`,
`consent_forms`, `qa_assessments`. Phase 2 continues with the visit/consultation detail screen
(vitals, doctor's consultation notes, disposition) — registration + queueing is done, the doctor
side of the visit is not.

---

## 2. Roles & permission model (adapting §1 of HMIS-COMPLETE.md)

The granular-permission design is *already scaffolded in SQL* (`permissions`,
`role_permissions`, `user_permission_overrides`) but has no admin UI and nothing checks it yet.
Keep the two-layer model exactly as specified:

- **`base_role`** on `users` (ADMIN/DOCTOR/MANAGER/ACCOUNTANT/NURSE/RECEPTIONIST) — dashboard routing only, already implemented.
- **Granular permissions** — `role_permissions` gives each base role its default permission set;
  `user_permission_overrides` lets admin grant/revoke individual permissions per person
  (`granted = 1` adds, `granted = 0` revokes a role-default). A `has_permission($userId, $key)`
  helper in `config/permissions.php` (new) resolves: start from role defaults, apply overrides,
  cache in `$_SESSION` per login.

**Next concrete step here:** seed `permissions` with the categories from §1.2
(NURSING_*, RECEPTION_*, CLINICAL_*, FINANCIAL_*, ADMIN_*), seed `role_permissions` with sane
defaults per role, then build one admin page `permissions.php` (role defaults) — the granular
per-staff override screen shown in §9.2 can reuse the same checkbox-grid markup pattern already
used for doc types in `staff.php`.

**Cross-role coverage (2026-07-16 decision):** there is no shift-based scheduling in this system —
confirmed explicitly, do not reintroduce a `shift_assignments`/time-window overlay concept anywhere
in this plan. When a receptionist needs to cover a nursing task because no nurse is free (record
vitals, log a short-stay event, etc.), this is handled purely through the existing permission
model: admin grants that specific person the relevant nursing permission(s)
(`vitals.record`, `short_stay_event.record`, etc.) via the per-user override UI in `staff.php`,
either as a standing grant or toggled off again later. No separate "acting as nurse" mode, no
second login, no flag distinguishing a covering receptionist's entry from a regular nurse's entry —
the vitals/event record is simply tagged with `recorded_by_id` = that person's real account, same
as any other entry. Whether someone *can* record a nursing action is entirely a permission
question, never a role-identity or shift-state question.

---

## 3. Database schema additions (MySQL, dated/versioned pattern)

Same insert-only, never-update pattern as `HMIS-COMPLETE.md` §11–12, just as plain MySQL tables.
New files go in `sql/`, one per feature, applied in order via phpMyAdmin (no local MySQL CLI —
per standing note, no local tooling exists for this stack).

```sql
-- sql/add_locations.sql
-- Admin-curated city/area lists (managed like doctor consultation types — staff pick from
-- what admin has set up, never free-type). Structured on purpose: this is the source for
-- branch-expansion reporting (patient density by city/area), so it must stay clean.
CREATE TABLE IF NOT EXISTS cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (city_id) REFERENCES cities(id),
    UNIQUE KEY uniq_area_per_city (city_id, name)
);

-- sql/add_patients.sql
CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    father_name VARCHAR(150),
    dob DATE NULL,
    phone VARCHAR(30) NOT NULL,
    city_id INT NULL,
    area_id INT NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (city_id) REFERENCES cities(id),
    FOREIGN KEY (area_id) REFERENCES areas(id),
    INDEX idx_patient_search (name, phone, father_name)
);

-- sql/add_visits.sql
CREATE TABLE IF NOT EXISTS visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    payment_mode ENUM('CASH','DIGITAL') NOT NULL,
    disposition ENUM('OPD','SHORT_STAY') NULL,
    visit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS vitals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL UNIQUE,
    height DECIMAL(5,2), weight DECIMAL(5,2),
    bp_systolic INT, bp_diastolic INT,
    temperature DECIMAL(4,1), heart_rate INT, o2_sat INT, rr INT,
    recorded_by_id INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL UNIQUE,
    notes TEXT, diagnosis TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE
);

-- sql/add_financial_terms.sql  (dated/versioned — never UPDATE, always INSERT)
CREATE TABLE IF NOT EXISTS doctor_financial_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    consultation_fee DECIMAL(10,2) NOT NULL,
    effective_from DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS rate_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    base_rate DECIMAL(10,2) NOT NULL,
    doctor_share_pct DECIMAL(5,2) NOT NULL,
    clinic_share_pct DECIMAL(5,2) NOT NULL,
    effective_from DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tax_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flat_sales_tax_pct DECIMAL(5,2) NOT NULL,
    consolidation_rate_pct DECIMAL(5,2) NOT NULL,
    effective_from DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS staff_commission_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    on_duty_staff_pct DECIMAL(5,2) NOT NULL DEFAULT 50,
    on_duty_clinic_pct DECIMAL(5,2) NOT NULL DEFAULT 50,
    off_duty_staff_pct DECIMAL(5,2) NOT NULL DEFAULT 100,
    off_duty_clinic_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    effective_from DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- sql/add_procedures.sql
CREATE TABLE IF NOT EXISTS procedure_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    base_charge DECIMAL(10,2) NOT NULL,
    doctor_share_pct DECIMAL(5,2) NOT NULL,
    mandatory_consent TINYINT(1) NOT NULL DEFAULT 0,
    consent_template TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    procedure_master_id INT NOT NULL,
    doctor_id INT NULL,
    staff_id INT NULL,
    staff_gender ENUM('MALE','FEMALE') NULL,
    base_charge DECIMAL(10,2) NOT NULL,
    visit_charge DECIMAL(10,2) NULL,
    doctor_share_pct DECIMAL(5,2) NULL,
    performer_earns DECIMAL(10,2) NOT NULL,
    clinic_earns DECIMAL(10,2) NOT NULL,
    on_duty_at_time TINYINT(1) NULL,
    status ENUM('pending','finalized') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (procedure_master_id) REFERENCES procedure_master(id)
);

CREATE TABLE IF NOT EXISTS consent_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    procedure_id INT NULL,
    template_used TEXT NOT NULL,
    consent_giver_name VARCHAR(150),
    consent_giver_relation VARCHAR(100),
    consent_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('generated','signed_uploaded') NOT NULL DEFAULT 'generated',
    scanned_file_path VARCHAR(255) NULL,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (procedure_id) REFERENCES procedures(id)
);

-- sql/add_short_stay.sql
CREATE TABLE IF NOT EXISTS beds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bed_code VARCHAR(20) NOT NULL UNIQUE,
    bed_type ENUM('indoor','emergency') NOT NULL,
    rate_per_hour DECIMAL(10,2) NOT NULL,
    status ENUM('vacant','occupied') NOT NULL DEFAULT 'vacant'
);

CREATE TABLE IF NOT EXISTS short_stay_admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL UNIQUE,
    bed_id INT NOT NULL,
    admission_at TIMESTAMP NOT NULL,
    discharge_at TIMESTAMP NULL,
    hours_stayed DECIMAL(6,2) NULL,
    bed_charge DECIMAL(10,2) NULL,
    reason VARCHAR(255),
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (bed_id) REFERENCES beds(id)
);

CREATE TABLE IF NOT EXISTS short_stay_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    short_stay_admission_id INT NULL,
    event_type VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    quantity INT NOT NULL DEFAULT 1,
    duration_min INT NULL,
    rate_id INT NOT NULL,
    recorded_by_id INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (short_stay_admission_id) REFERENCES short_stay_admissions(id),
    FOREIGN KEY (rate_id) REFERENCES rate_master(id),
    FOREIGN KEY (recorded_by_id) REFERENCES users(id)
);

-- sql/add_billing.sql
CREATE TABLE IF NOT EXISTS bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL UNIQUE,
    line_items_json JSON NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    flat_tax_amount DECIMAL(10,2) NOT NULL,
    consolidation_amount DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    total_staff_earnings DECIMAL(10,2) NOT NULL,
    total_clinic_earnings DECIMAL(10,2) NOT NULL,
    payment_mode ENUM('CASH','DIGITAL') NOT NULL,
    status ENUM('pending','paid','partial') NOT NULL DEFAULT 'pending',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE
);

-- sql/add_qa.sql
-- (no shift_assignments table — this system has no shift-based scheduling; cross-role
-- coverage, e.g. a receptionist recording vitals when no nurse is free, is handled purely
-- via permission grants on the person's existing account, see §2.)
CREATE TABLE IF NOT EXISTS qa_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    nurse_id INT NOT NULL,
    assessor_id INT NOT NULL,
    cannulation_technique TINYINT, patient_communication TINYINT,
    procedure_adherence TINYINT, bedside_manner TINYINT,
    notes TEXT,
    assessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id),
    FOREIGN KEY (nurse_id) REFERENCES users(id),
    FOREIGN KEY (assessor_id) REFERENCES users(id)
);

-- Expenses, doctor/staff settlements — same idea, plain tables mirroring
-- HMIS-COMPLETE.md §12.1 ExpenseCategory/Expense/DoctorSettlement/StaffSettlement models.
```

`gender` also needs adding to `users` (nullable, `ENUM('MALE','FEMALE')`) for the visit-charge
calculation in §11.2B — small `ALTER TABLE users ADD COLUMN gender ...` migration.

**Discount cap (2026-07-17):** receptionists/staff can never edit the consultation *fee* itself
(that's always doctor+type driven, per `doctor_financial_terms`/`rate_master`), but admin can let
specific people apply a discount up to a per-person percentage cap at checkout. This is a numeric
cap, not a boolean, so it doesn't fit the existing boolean `permissions`/`role_permissions` model —
add it as a plain column instead of a new permission key:

```sql
ALTER TABLE users
  ADD COLUMN max_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
```

Admin sets this per-person in `staff.php` (defaults to 0 = no discount allowed). At checkout,
`checkout.php` validates `requested_discount_pct <= users.max_discount_pct` for the logged-in
user server-side (never trust a client-submitted cap) and logs the applied discount + who granted
it to `audit_logs`. No role-level default/override layering needed — unlike granular permissions,
discount authority is a manager-assigned trust level per individual, not something role defaults
make sense for.

**City/Area capture (2026-07-17):** patient registration captures City and Area as structured,
admin-curated dropdowns (`cities`/`areas` tables above), not free text — this data is the
explicit basis for future branch-expansion decisions (where patient density justifies opening a
new location), so it has to stay clean rather than accumulate typo'd variants. Area is a dependent
dropdown filtered by the selected City, same interaction pattern as doctor → consultation type.

**Ownership split (2026-07-17):** admin manages the canonical list on a dedicated `locations.php`
page (add/edit/remove cities and areas). Receptionists can also add a new area inline from the
registration form itself (an "+ Add new area..." option in the Area dropdown) when a patient's
neighborhood genuinely isn't listed yet — this is **live immediately**, not blocked on approval,
so registration is never held up waiting on admin. Reception-added areas are inserted with a
`status = 'pending'` flag and surface in a review queue at the top of `locations.php`, showing who
added it, how many patients are already using it, and one-click actions to approve as-is, rename
(fix a typo), or merge into an existing area (collapsing near-duplicates like "Bahria Twn Ph 8"
into "Bahria Town Phase 8"). This mirrors the tradeoff already made for granular permissions —
never block the operational task, catch and clean up data quality asynchronously instead.

```sql
ALTER TABLE areas
  ADD COLUMN added_by_id INT NULL,
  ADD COLUMN status ENUM('active','pending') NOT NULL DEFAULT 'active',
  ADD FOREIGN KEY (added_by_id) REFERENCES users(id);
-- admin-added rows: added_by_id NULL, status 'active'
-- reception quick-add rows: added_by_id = their user id, status 'pending' until admin approves
```

Mocked at `locations-mockup.html` (two-pane city list / area table + pending-review banner with
merge dropdown) — not built yet, add to the Phase 2 page list alongside `patients.php`.

All "effective on visit date" lookups become a single reusable helper:

```php
function effectiveRate(PDO $pdo, string $table, string $dateCol, array $where, string $onDate): ?array {
    // SELECT * FROM $table WHERE ...$where AND $dateCol <= ? ORDER BY $dateCol DESC LIMIT 1
}
```

used for `doctor_financial_terms`, `rate_master`, `tax_config`, `staff_commission_config` — never
recalculates old bills, exactly as specified.

---

## 4. Page-per-feature plan (replaces Next.js route tree)

Each maps to one `.php` file at the project root (or a subfolder if it grows), following the
existing `staff.php` structure: guard include → POST handler(s) at top → SELECT + render below.

| Feature (HMIS-COMPLETE.md §) | New PHP page(s) |
|---|---|
| Permissions & roles (§9.2) | `permissions.php` (role defaults), extend `staff.php` edit panel with per-user override checkboxes |
| Patient registration/search (§3.1) | `patients.php` |
| Visit/OPD slip (§3.2) | `visit_new.php`, `visit_slip.php` (printable) |
| Vitals (§3.3) | inline section on `visit.php` (visit detail page) |
| Nurse rotation (§3.4) | `nurse_rotation.php` (admin config) + auto-assign logic inside short-stay admission flow |
| Consultation/disposition (§3.5) | section on `visit.php` |
| Medical record/doc store (§3.6) | extend `document.php` pattern to visits, reuse `staff_documents`-style table scoped to visit/patient |
| Short-stay & beds (§3.7) | `beds.php` (admin bed master), `short_stay.php` (admission/discharge/events) |
| Procedures & consent (§3.8, §3.13) | `procedure_master.php` (admin), `procedure_record.php`, `consent_print.php` |
| Billing/invoice (§3.9, §3.16) | `checkout.php` (generates `bills` row + invoice HTML/print view) |
| Doctor profile/credentials (§3.10) | extend `staff.php` (doctor doc types already exist) |
| Financial terms/rate master/tax (§3.11) | `financial_config.php` (three tabs: doctor fees, rate master, tax config) |
| Expenses (§3.12) | `expense_categories.php`, `expenses.php` |
| Staff commission config (§3.15, §3.15A) | `staff_commission_config.php`, plus per-staff service assignment modal on `staff.php` |
| QA & feedback (§3.14) | `qa_assessments.php`, `patient_feedback.php` |
| Audit logs (§3.17) | `audit_logs.php` (admin, already logging — just needs a viewer) |
| Reports/settlements | `reports.php`, `doctor_settlements.php`, `staff_settlements.php` |

Dashboard routing (§10) stays as-is: `base_role` picks the landing page after login; each
non-admin dashboard is a stripped-down view over the same tables, gated by `has_permission()`.

---

## 5. Revised build phases (against actual current state)

| Phase | Status | Scope |
|---|---|---|
| **0 — Foundation** | **Done** | Auth, admin guard, first-admin seed, staff/doctor CRUD + documents, audit log write-path |
| **1 — Permissions UI** | **Done** | Seeded `permissions`/`role_permissions`, built `permissions.php`, wired per-user override UI into `staff.php` (commit `43ab26e`); `has_permission()` enforcement still needs to be added to each new guarded page as it's built |
| **2 — Patients & OPD core loop** | **In progress** | `patients.php` registration + queueing **done 2026-07-17** (patient search, register-and-queue with doctor consult-type/fee lookup and discount cap, `cities`/`areas` reference data + `locations.php`); still needed: visit detail page (vitals, consultation notes, disposition), OPD slip printing, prescription scan |
| **3 — Procedures & consent** | Not started | Procedure master, procedure recording (doctor or staff), consent templates/printing |
| **3A — Staff commission** | Not started | `gender` column, duty-based split config, visit-charge calc (no shift assignments — see §2) |
| **4 — Financial config & invoicing** | Not started | Doctor financial terms, rate master, tax config, `checkout.php` invoice generation |
| **5 — Short-stay & beds** | Not started | Bed master, admission/discharge, chargeable events, bed status dashboard |
| **6 — Reporting & QA** | Not started | Settlements, P&L, QA spot-checks, patient feedback, audit log viewer |
| **7 — Polish** | Not started | Permission enforcement audit, UI pass, bug fixes |

Each phase = one or more `sql/add_*.sql` files (applied via phpMyAdmin) + the PHP pages listed
above + a commit/push through the existing FTP auto-deploy workflow. Per standing rule: never push
schema-dependent PHP before its migration has actually been run on the live database.

---

## 6. Carried-over design principles (unchanged from HMIS-COMPLETE.md §14)

All 20 principles in the original doc still apply verbatim — dated/versioned financial records,
recorder-always-tagged, audit everything, QA sampled not mandatory, consent printed before
procedure, doctor/staff splits per-procedure not blanket, procedures performed by doctor OR staff
not both, invoice generation is must-have, granular/mixable permissions, permission removal is
immediate, staff commission separate from salary. Nothing here changes those — only *how* they're
implemented (MySQL tables + PHP pages instead of Prisma + Next.js).

---

## 7. Visual design — two undecided candidate directions

Neither is confirmed as canonical. **Do not build out remaining role dashboards (Doctor, Nurse,
Manager, Accountant) against either palette until this is resolved** — building against the loser
means a mismatched rebuild.

### Direction 1a — Deep Blue (original, from HMIS-COMPLETE.md's design guide)

- Primary: `#1E3A8A` → `#3B82F6` gradient
- Base font: Inter
- Spacing base: 8px
- Border radius: 8px (inputs/buttons), 12px (cards/modals)
- WCAG AA verified combinations documented in the original design guide §10.1
- Full component-level spec (buttons, inputs, cards, modals, login page layout, animations) exists
  in chat history 2026-07-15, "HMIS — PREMIUM UI/UX DESIGN GUIDE" — not yet copied into this repo.

### Direction 1b — Soft Green (candidate, proposed alongside a Receptionist Dashboard mock)

OKLCH-based, single-hue green system — no additional accent colors besides the amber warning tone.

| Role | Value | Usage |
|---|---|---|
| Sidebar background | `oklch(28% 0.05 150)` | Sidebar fill |
| Sidebar active item bg | `oklch(38% 0.06 150)` | Active nav row |
| Primary accent | `oklch(45% 0.11 150)` | Primary buttons, active states, links |
| Accent light (icon bg) | `oklch(70% 0.13 150)` | Logo mark, avatar bg |
| Page background | `oklch(97% 0.008 150)` | Main canvas behind cards |
| Card background | `white` | Stat & list cards |
| Quick-card tint | `oklch(95% 0.02 150)` | Patients / Post Expense card fill |
| Body text (dark) | `oklch(18–25% 0.01–0.02 150)` | Headings, primary text |
| Muted text | `oklch(48–55% 0.01–0.02 150)` | Secondary/sub text |
| Warning/expense accent | `oklch(62% 0.1 60)` | Post Expense icon, amber status |
| Status — waiting | bg `oklch(93% 0.03 60)` / text `oklch(45% 0.08 60)` | Queue "Waiting" badge |
| Status — in consult | bg `oklch(93% 0.03 150)` / text `oklch(40% 0.1 150)` | Queue "In Consult" badge |

- **Typography:** Helvetica / system sans-serif, single family, weight 500–700 for emphasis
  (differs from Direction 1a's Inter).
- **Style notes:** rounded 16px cards, soft shadows (`0 1px 3px rgba(0,0,0,0.04)`), pill-shaped
  topbar controls (22px radius).

#### Receptionist Dashboard layout (mocked under Direction 1b, but the layout itself is
palette-independent and applies regardless of which direction wins)

**Sidebar** (236px, fixed): clinic logo + name, nav (Dashboard, Patients, Appointments/OPD,
Admissions, Billing & Cash, Discharge, Reports), user profile footer.

**Top bar** (68px, fixed/sticky): page title "Reception Desk", patient search, and Quick Actions —
**Patients**, **+ Add Patient**, **Post Expense**.

**Content** (scrollable):
- Quick action cards: Patients, + Add New Patient, Post Expense
- Stat cards: Cash Tally Today, New Admissions, OPD Patients Today, Discharges Today
- OPD Queue list (token, patient, doctor, status)
- Doctor Schedule list

Note: this nav list (Appointments/OPD, Admissions, Billing & Cash, Discharge) reflects the
*target* receptionist dashboard once Phases 2, 4, and 5 land (patients/visits, billing,
short-stay respectively) — none of those exist yet per §5's phase table. The receptionist
dashboard can't be built for real until those phases are done; treat this mock as forward
reference, not a Phase 1 deliverable.
