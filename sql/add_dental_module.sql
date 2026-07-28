-- =============================================================================
-- Dental module: treatment records, package accounts, consent, lab (2026-07-28)
--
-- WHAT DENTAL ACTUALLY ADDS
-- Dental reuses registration, the consultation fee, the P-series procedure bill
-- and the doctor-share config unchanged. It breaks the app's assumptions in
-- exactly ONE place: a crown or an ortho case is quoted once and paid across
-- many visits. Every table below exists to serve that one fact, plus the
-- clinical record and consent that a physical intervention needs.
--
-- THE TWO MONEY PATHS (this is the design, everything else follows from it)
--   Small, same-visit work (a filling, an extraction) bills on the EXISTING
--   procedure_bills P-series and never touches these tables. No account.
--
--   Multi-visit / high-value work opens a PACKAGE ACCOUNT here: an itemised
--   quote the patient pays down over months. Opening an account does NOT raise
--   a procedure_bill — the money is tracked by this ledger instead, because a
--   prepaid one-shot bill cannot represent "Rs 90,000 quoted, Rs 20,000 paid".
--
-- THE NO-TOTAL RULE
-- dental_procedure_accounts has NO total column, deliberately. The total is
-- always SUM of the live (non-voided) items. A stored total is a second source
-- of truth that drifts the first time someone voids an item and the recompute
-- is missed — and on a package the patient signed a figure for, a drifted total
-- is a dispute. The items ARE the quote; the quote explains itself.
--
-- AUDIT = ITEM HISTORY
-- No adjustments table. Every item carries added_by/at, and a dropped item is
-- VOIDED with a reason (never deleted), so the account's history is readable
-- straight off the items. Same for payments.
--
-- WHY lab_charge SITS ON THE ITEM AND NOT INSIDE amount
-- The clinic pays the lab vendor; it is a pass-through, not clinic income and
-- not doctor-shareable. Folding it into `amount` would quietly pay the dentist
-- a share of the vendor's fee. So:
--     account total = SUM(amount + lab_charge)   <- what the patient owes
--     doctor split  = computed on `amount` alone, with lab_charge passed as the
--                     `disposables` argument to doctor_split() so the clinic
--                     recovers it off the top and the parts still re-sum to gross
-- This is the same three-step rule as procedure disposables; see
-- sql/add_procedure_disposables.sql for the worked example.
--
-- BALANCE NEVER BLOCKS TREATMENT
-- Nothing here locks on an outstanding balance. The system SHOWS what is owed;
-- whether to proceed is a front-desk policy call, not a database constraint.
--
-- Flat and idempotent; no stored procedures (the Hostinger user is denied
-- CREATE ROUTINE #1044). No ENGINE/CHARSET clause anywhere — forcing
-- utf8mb4 in add_procedure_bills.sql mismatched patients/users and made every
-- FK fail with errno 150, which silently aborted the CREATEs while the INSERTs
-- succeeded, so the migration LOOKED fine and left the page fatal. Inherit the
-- server default.
-- Run in phpMyAdmin against the hims database.
--
-- RUN ORDER: add_dental_procedure_fields.sql FIRST, then this file, then
-- add_dental_permissions.sql.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Number counters. Two series, same shape as procedure_invoice_counters.
--
--    DA = the account (one per package)      e.g. DA1 2607
--    DP = a payment receipt (many per account) e.g. DP1 2607, DP2 2607
--
--    DP needs its own series precisely BECAUSE one account produces N receipts;
--    reusing the P series would collide, since generate_procedure_invoice_number()
--    derives its next value from MAX() over procedure_bills.
--
--    NOTE for whoever writes the generators: these prefixes are TWO characters,
--    so the sequence is SUBSTRING(number, 3, CHAR_LENGTH(number) - 6) — not the
--    (2, len-5) the one-character P/A/E/I series use. Get that wrong and the
--    parsed sequence is garbage, the counter restarts at 1, and the UNIQUE key
--    throws on the second account of the month.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_account_counters (
    yr       SMALLINT NOT NULL,
    mo       TINYINT  NOT NULL,
    next_seq INT      NOT NULL DEFAULT 1,
    PRIMARY KEY (yr, mo)
);

CREATE TABLE IF NOT EXISTS dental_payment_counters (
    yr       SMALLINT NOT NULL,
    mo       TINYINT  NOT NULL,
    next_seq INT      NOT NULL DEFAULT 1,
    PRIMARY KEY (yr, mo)
);


-- -----------------------------------------------------------------------------
-- 2. The package account. Money only — see dental_treatment_records for what
--    was actually done at the chair.
--
--    NO total column. See the header.
--
--    status:
--      OPEN      accepting items and payments
--      COMPLETED balance settled and the work finished
--      CANCELLED abandoned mid-way. Freezes the account: the app refuses further
--                items and payments, so the balance stops moving WITHOUT storing
--                a snapshot (which would violate the no-total rule). If the
--                patient had overpaid at that point the balance goes negative
--                and the app surfaces it as REFUND DUE rather than swallowing it.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_procedure_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_number VARCHAR(24) NOT NULL,
    patient_id     INT NOT NULL,
    -- The dentist who owns the case and earns the share on its items. NOT NULL
    -- for the same reason procedure_bills.doctor_id is: no doctor, no share,
    -- no clinical owner.
    doctor_id      INT NOT NULL,
    -- Free text, e.g. "Upper arch rehabilitation". Names the case for reception.
    title          VARCHAR(150) NOT NULL,
    status         ENUM('OPEN','SETTLED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    -- The patient's category discount as it stood when the account opened,
    -- snapshotted so a later category change cannot re-price a signed quote.
    discount_pct   DECIMAL(5,2) NOT NULL DEFAULT 0,
    notes          TEXT NULL,
    opened_by_id   INT NOT NULL,
    opened_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_at     DATETIME NULL,
    settled_by_id  INT NULL,
    cancelled_at   DATETIME NULL,
    cancelled_by_id INT NULL,
    cancel_reason  VARCHAR(255) NULL,
    UNIQUE KEY uq_dpa_number (account_number),
    KEY idx_dpa_patient (patient_id, status),
    KEY idx_dpa_doctor (doctor_id, status),
    CONSTRAINT fk_dpa_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_dpa_doctor  FOREIGN KEY (doctor_id)  REFERENCES users(id)
);


-- -----------------------------------------------------------------------------
-- 3. The account's line items. THIS is where the total lives.
--
--    "3 root canals + 3 crowns" is 6 rows, each with its own tooth, so the
--    quote explains itself and a dispute can be answered line by line.
--
--    Voided rows are kept and struck through in the UI, never deleted: a
--    dropped crown with a reason is exactly the audit trail the spec asks for.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_procedure_account_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id          INT NOT NULL,
    -- SET NULL on delete: retiring a catalogue entry must never delete billing
    -- history. description below keeps the row readable afterwards.
    procedure_master_id INT NULL,
    description         VARCHAR(150) NOT NULL,
    -- FDI two-digit tooth (11-18, 21-28, 31-38, 41-48 permanent; 51-55, 61-65,
    -- 71-75, 81-85 primary). NULL for whole-mouth work. Validated in PHP by
    -- is_valid_fdi() against the explicit legal set — a regex would admit 19/29/56.
    tooth_fdi           VARCHAR(2) NULL,
    quantity            INT NOT NULL DEFAULT 1,
    -- List rate before the account's discount.
    unit_rate           DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Post-discount procedure fee for this line. The doctor's share is computed
    -- on THIS, never on amount + lab_charge.
    amount              DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Outside-lab cost for this line, charged on top and always visible.
    -- Burying it inside the quoted total silently eats the clinic's margin.
    lab_charge          DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- ---- Share snapshot, copied from doctor_procedures when the item is added.
    -- Per-LINE, not one rate per doctor: dental mixes a 70% endo with a 50%
    -- prosthetic on the same account, so an aggregate split would be wrong.
    doctor_share_pct    DECIMAL(5,2) NOT NULL DEFAULT 0,
    has_tax             TINYINT      NOT NULL DEFAULT 0,
    tax_percent         DECIMAL(5,2) NOT NULL DEFAULT 0,
    added_by_id         INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at           DATETIME NULL,
    voided_by_id        INT NULL,
    void_reason         VARCHAR(255) NULL,
    -- Every total query filters on (account_id, voided_at IS NULL).
    KEY idx_dpai_account (account_id, voided_at),
    CONSTRAINT fk_dpai_account FOREIGN KEY (account_id) REFERENCES dental_procedure_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dpai_master  FOREIGN KEY (procedure_master_id) REFERENCES procedure_master(id) ON DELETE SET NULL
);


-- -----------------------------------------------------------------------------
-- 4. Payments against an account. Partial, across visits, receipt each time.
--
--    Shape differs from every *_bills table on purpose: there is no
--    paid_amount column, because a payment row IS the money. Anything reading
--    these for cash (day_cash_tally, clinic_revenue) must SUM(amount), not
--    SUM(paid_amount).
--
--    paid_by_id IS THE DRAWER. Day closing attributes this cash to that user's
--    shift, exactly as bills.paid_by_id does. Not the person who clicked —
--    the person whose till it went into.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_procedure_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(24) NOT NULL,
    account_id     INT NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','card','bank_transfer','cheque') NOT NULL DEFAULT 'cash',
    -- No 'waived' here: a zero payment is not a payment. Waiving happens by
    -- voiding items, which lowers the total.
    status         ENUM('paid','voided') NOT NULL DEFAULT 'paid',
    paid_at        DATETIME NOT NULL,
    paid_by_id     INT NOT NULL,
    created_by_id  INT NOT NULL,
    -- Which visit the money came in on, when known. Nullable: a patient can pay
    -- an instalment at the desk without being booked in that day.
    visit_id       INT NULL,
    notes          VARCHAR(255) NULL,
    printed_at     DATETIME NULL,
    printed_by_id  INT NULL,
    voided_at      DATETIME NULL,
    voided_by_id   INT NULL,
    void_reason    VARCHAR(255) NULL,
    UNIQUE KEY uq_dpp_receipt (receipt_number),
    KEY idx_dpp_account (account_id, status, voided_at),
    -- The day-closing lookup: this drawer, this business-day window.
    KEY idx_dpp_drawer (paid_by_id, paid_at, voided_at),
    CONSTRAINT fk_dpp_account FOREIGN KEY (account_id) REFERENCES dental_procedure_accounts(id)
);


-- -----------------------------------------------------------------------------
-- 5. Consent. Two jobs in one table.
--
--    (a) Clinical: a procedure flagged mandatory_consent cannot be billed until
--        a signed consent exists. Print -> sign -> upload scan; no signature pad.
--    (b) Financial: for a package, the consent IS the contract. financial_snapshot
--        freezes the itemised quote + total + upfront + remaining AT SIGNING, so
--        the patient's agreed figure survives any later add/void of items.
--
--    financial_snapshot is TEXT holding json_encode() output, NOT a JSON column:
--    the JSON type needs MySQL 5.7.8+ and gains nothing here — the app never
--    queries inside it, it only renders it back on the printed consent.
--
--    scan_path stores the GENERATED FILENAME ONLY, never a caller-supplied path.
--    The directory is fixed in PHP, so path traversal is impossible by
--    construction. Same rule as staff documents.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id          INT NOT NULL,
    doctor_id           INT NULL,
    -- Set when this consent is a package's financial contract.
    account_id          INT NULL,
    -- Set when it covers one flagged procedure instead.
    procedure_master_id INT NULL,
    visit_id            INT NULL,
    -- Human label for what was consented to, snapshotted so it reads correctly
    -- even if the catalogue row is retired later.
    procedure_ref       VARCHAR(255) NULL,
    consent_text        TEXT NULL,
    financial_snapshot  TEXT NULL,
    status              ENUM('PENDING','SIGNED') NOT NULL DEFAULT 'PENDING',
    signed_at           DATETIME NULL,
    -- Who physically signed — the patient, or a guardian for a minor.
    signed_name         VARCHAR(150) NULL,
    signed_relation     VARCHAR(60)  NULL,
    scan_path           VARCHAR(255) NULL,
    scan_original_name  VARCHAR(255) NULL,
    scan_size           INT NULL,
    uploaded_by_id      INT NULL,
    captured_by_id      INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at           DATETIME NULL,
    voided_by_id        INT NULL,
    void_reason         VARCHAR(255) NULL,
    KEY idx_dc_patient (patient_id, status),
    KEY idx_dc_account (account_id),
    -- The billing gate's lookup: has THIS patient signed for THIS procedure?
    KEY idx_dc_gate (patient_id, procedure_master_id, status, voided_at),
    CONSTRAINT fk_dc_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_dc_account FOREIGN KEY (account_id) REFERENCES dental_procedure_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_dc_master  FOREIGN KEY (procedure_master_id) REFERENCES procedure_master(id) ON DELETE SET NULL
);


-- -----------------------------------------------------------------------------
-- 6. Lab work. The status track is the point.
--
--    A crown vanishing into a drawer for three weeks with nobody accountable is
--    the one dental-specific operational failure the rest of the system cannot
--    see. SENT -> RECEIVED -> FITTED with a vendor and dates costs four columns
--    and makes it visible; an overdue list falls straight out of
--    (status='SENT' AND expected_date < CURDATE()).
--
--    lab_charge here is what the CLINIC PAYS THE VENDOR. What the PATIENT pays
--    is dental_procedure_account_items.lab_charge. They are usually the same
--    number but they are not the same fact, and a clinic that marks lab work up
--    needs them separate.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_lab_work (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id       INT NOT NULL,
    -- Whichever money path this lab work belongs to; both NULL is allowed for
    -- work logged before it is attached to anything.
    account_id       INT NULL,
    account_item_id  INT NULL,
    procedure_bill_id INT NULL,
    doctor_id        INT NULL,
    vendor_name      VARCHAR(150) NOT NULL,
    work_description VARCHAR(255) NOT NULL,
    tooth_fdi        VARCHAR(2) NULL,
    -- Crown/denture shade, e.g. A2. Free text: shade guides vary by vendor.
    shade            VARCHAR(20) NULL,
    lab_charge       DECIMAL(10,2) NOT NULL DEFAULT 0,
    status           ENUM('SENT','RECEIVED','FITTED') NOT NULL DEFAULT 'SENT',
    sent_date        DATE NOT NULL,
    -- Drives the overdue list. Nullable — not every vendor commits to a date.
    expected_date    DATE NULL,
    received_date    DATE NULL,
    fitted_date      DATE NULL,
    notes            TEXT NULL,
    created_by_id    INT NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by_id    INT NULL,
    updated_at       DATETIME NULL,
    voided_at        DATETIME NULL,
    voided_by_id     INT NULL,
    void_reason      VARCHAR(255) NULL,
    KEY idx_dlw_patient (patient_id),
    KEY idx_dlw_account (account_id),
    -- The worklist and the overdue view.
    KEY idx_dlw_status (status, expected_date),
    CONSTRAINT fk_dlw_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_dlw_account FOREIGN KEY (account_id) REFERENCES dental_procedure_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_dlw_item    FOREIGN KEY (account_item_id) REFERENCES dental_procedure_account_items(id) ON DELETE SET NULL
);


-- -----------------------------------------------------------------------------
-- 7. The per-visit clinical record. What happened at the chair.
--
--    Separate from the account on purpose: the account is MONEY, this is
--    CLINICAL. Same procedure on 3 teeth = 3 rows. Prior visits render
--    read-only so continuity cannot be rewritten.
--
--    procedure_bill_id and account_id record which money path settled the work
--    (at most one is set; both NULL means recorded but not yet billed).
--
--    account_id's FK is added at the BOTTOM of this file, not here — MySQL
--    cannot reference dental_procedure_accounts from a table created before it
--    in the same script.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dental_treatment_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id          INT NOT NULL,
    doctor_id           INT NOT NULL,
    visit_date          DATE NOT NULL,
    visit_id            INT NULL,
    procedure_master_id INT NULL,
    -- Snapshot: keeps the record readable after a catalogue row is retired.
    procedure_name      VARCHAR(150) NOT NULL,
    tooth_fdi           VARCHAR(2) NULL,
    charge              DECIMAL(10,2) NOT NULL DEFAULT 0,
    findings            TEXT NULL,
    treatment_notes     TEXT NULL,
    next_visit_plan     VARCHAR(255) NULL,
    -- Which money path settled this, if any.
    procedure_bill_id   INT NULL,
    account_id          INT NULL,
    performed_by_id     INT NOT NULL,
    entered_by_id       INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at           DATETIME NULL,
    voided_by_id        INT NULL,
    void_reason         VARCHAR(255) NULL,
    KEY idx_dtr_patient (patient_id, visit_date),
    KEY idx_dtr_doctor (doctor_id, visit_date),
    KEY idx_dtr_account (account_id),
    CONSTRAINT fk_dtr_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_dtr_master  FOREIGN KEY (procedure_master_id) REFERENCES procedure_master(id) ON DELETE SET NULL
);


-- -----------------------------------------------------------------------------
-- 8. Deferred FK: treatment record -> account.
--
--    Split out because dental_treatment_records is created above but references
--    dental_procedure_accounts, and MySQL will not accept a forward reference
--    inside one script.
--
--    NOT IDEMPOTENT. Unlike every CREATE above, re-running this file errors here
--    with #1826 "Duplicate foreign key constraint name". That error is harmless
--    and means the constraint is already in place — everything above it will
--    have been skipped by IF NOT EXISTS as normal.
-- -----------------------------------------------------------------------------
ALTER TABLE dental_treatment_records
    ADD CONSTRAINT fk_dtr_account FOREIGN KEY (account_id)
        REFERENCES dental_procedure_accounts(id) ON DELETE SET NULL;


-- =============================================================================
-- End dental module migration.
--
-- Verify with (fully qualified — querying information_schema switches
-- phpMyAdmin's current-database context, which is what made an earlier verify
-- block fail with "#1109 Unknown table 'permissions' in information_schema"):
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = 'u402528120_hmis'
--      AND table_name IN ('dental_account_counters','dental_payment_counters',
--                         'dental_procedure_accounts','dental_procedure_account_items',
--                         'dental_procedure_payments','dental_consents',
--                         'dental_lab_work','dental_treatment_records');
--   -- expect 8 rows. FEWER THAN 8 MEANS AN FK FAILED (errno 150) AND THE CREATE
--   -- SILENTLY ABORTED — do not assume success from "no error shown".
--
--   SELECT constraint_name, table_name FROM information_schema.table_constraints
--    WHERE table_schema = 'u402528120_hmis' AND constraint_type = 'FOREIGN KEY'
--      AND table_name LIKE 'dental_%';
--   -- expect 10: fk_dpa_patient, fk_dpa_doctor, fk_dpai_account, fk_dpai_master,
--   --   fk_dpp_account, fk_dc_patient, fk_dc_account, fk_dc_master,
--   --   fk_dlw_patient, fk_dlw_account, fk_dlw_item, fk_dtr_patient,
--   --   fk_dtr_master, fk_dtr_account  (14 including the deferred one)
--
--   -- The no-total invariant: this is the ONLY definition of an account's
--   -- total. It must match what dental_account.php shows, to the paisa.
--   SELECT a.account_number, a.status,
--          COALESCE(SUM(CASE WHEN i.voided_at IS NULL THEN i.amount END), 0)     AS charged,
--          COALESCE(SUM(CASE WHEN i.voided_at IS NULL THEN i.lab_charge END), 0) AS lab
--     FROM u402528120_hmis.dental_procedure_accounts a
--     LEFT JOIN u402528120_hmis.dental_procedure_account_items i ON i.account_id = a.id
--    GROUP BY a.id;
--
--   -- Series sanity after creating 2 accounts + 3 payments in one month:
--   SELECT account_number FROM u402528120_hmis.dental_procedure_accounts ORDER BY id;
--   -- expect DA1YYMM, DA2YYMM  -- NOT two DA1YYMM (that means the SUBSTRING
--   -- offset was copied from the 1-char P series instead of using (3, len-6))
--   SELECT receipt_number FROM u402528120_hmis.dental_procedure_payments ORDER BY id;
--   -- expect DP1YYMM, DP2YYMM, DP3YYMM
-- =============================================================================
