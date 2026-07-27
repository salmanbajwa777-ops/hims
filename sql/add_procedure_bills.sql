-- =============================================================================
-- Procedure billing — the transaction tables the catalogue has been waiting for.
--
-- procedure_master + doctor_procedures (sql/add_procedure_master.sql) have held
-- rates and share splits since 2026-07, but NOTHING ever wrote a procedure
-- transaction: doctor_share_statement.php rendered a permanent "billing not
-- live yet" row. These two tables close that gap.
--
-- Shape follows er_bills/er_bill_items exactly (prepaid, settled in ONE
-- transaction, nothing left open) — a procedure is a walk-in charge, not an
-- admission. Its own "P" invoice series so it never collides with the
-- consultation / A / E / I series.
--
-- MONEY MODEL. Unlike consultations (one share % per doctor on users.*), the
-- share is PER PROCEDURE: doctor_procedures carries its own fee override,
-- doctor_share_pct, has_tax and tax_percent. Those four are SNAPSHOT onto each
-- line at billing time, so re-pricing a procedure next month never rewrites
-- what a doctor already earned. Tax-first, same order as doctor_split():
--   tax = amount x tax_percent      -> remainder = amount - tax
--   doctor = remainder x share_pct  -> clinic = remainder - doctor
--
-- DISCOUNTS. discount_categories.procedures_pct already exists (see
-- add_discount_categories.sql) and patient_discount_category() already selects
-- it — no schema change needed. The category % is applied to the bill and
-- spread proportionally onto line amounts, exactly as er_bill.php does, so the
-- item sum always re-sums to grand_total and the slip shows a generic
-- "Discount" line rather than naming the category to the patient.
--
-- No stored procedures / no DELIMITER: the Hostinger DB user is denied
-- CREATE ROUTINE (#1044). Flat, idempotent statements only.
--
-- NO EXPLICIT ENGINE/CHARSET CLAUSE — deliberate, and load-bearing. The first
-- version of this file ended each CREATE with
-- "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"; forcing a charset that differs from
-- the referenced patients/users tables makes every FOREIGN KEY fail with
-- errno 150, so all three CREATEs silently aborted while the INSERT IGNOREs at
-- the end still succeeded. The result looked like a successful migration but
-- left procedure_bill.php fatal with "Table 'procedure_bills' doesn't exist".
-- add_er_bills.sql declares no ENGINE either — inherit the server default and
-- the FKs match whatever patients/users actually use.
--
-- Run in phpMyAdmin against the hims database. Timestamps are PKT (+05:00).
-- =============================================================================

-- 1. Invoice counter for the "P" series. Same (yr, mo) shape as the other
--    counters; generate_procedure_invoice_number() takes GREATEST(counter,
--    MAX(actual)) so a restore or a re-run migration can't re-issue a number.
CREATE TABLE IF NOT EXISTS procedure_invoice_counters (
    yr       SMALLINT NOT NULL,
    mo       TINYINT  NOT NULL,
    next_seq INT      NOT NULL DEFAULT 1,
    PRIMARY KEY (yr, mo)
);

-- 2. The bill header. Prepaid: raised and settled in one go, so paid_at /
--    payment_method are written on INSERT and there is no draft state.
--    voided_at mirrors the void_actions pattern — every money read in the app
--    filters on `voided_at IS NULL`, and the procedure reads do the same.
CREATE TABLE IF NOT EXISTS procedure_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number  VARCHAR(24) NOT NULL,
    patient_id      INT NOT NULL,
    -- The doctor who performs the procedure AND earns the share. Kept NOT NULL:
    -- a procedure with no doctor has no share to pay and no clinical owner.
    doctor_id       INT NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Category discount actually applied (0 when the patient has no category).
    discount_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    grand_total     DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- 'waived' when a 100% discount takes the total to zero; 'voided' is set by
    -- void_procedure_bill() alongside voided_at (mirrors er_bills exactly).
    status          ENUM('paid','waived','voided') NOT NULL DEFAULT 'paid',
    -- Lowercase 'cash'/'card' to match er_bills/bills — the day-closing tally
    -- and the Sheet log both branch on these exact values.
    payment_method  ENUM('cash','card','bank_transfer','cheque') NULL,
    paid_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_at         DATETIME NULL,
    paid_by_id      INT NULL,
    created_by_id   INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    printed_at      DATETIME NULL,
    printed_by_id   INT NULL,
    voided_at       DATETIME NULL,
    voided_by_id    INT NULL,
    void_reason     VARCHAR(255) NULL,
    UNIQUE KEY uniq_procedure_invoice (invoice_number),
    KEY idx_pb_patient (patient_id),
    -- The doctor-statement query filters doctor + paid_at + voided_at together.
    KEY idx_pb_doctor_paid (doctor_id, paid_at, voided_at),
    KEY idx_pb_paid_at (paid_at),
    FOREIGN KEY (patient_id)    REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id)     REFERENCES users(id),
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    FOREIGN KEY (paid_by_id)    REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (printed_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by_id)  REFERENCES users(id) ON DELETE SET NULL
);

-- 3. Line items. procedure_master_id is ON DELETE SET NULL, not CASCADE:
--    retiring a procedure from the catalogue must never delete the history of
--    it having been billed. `description` is the name snapshot that keeps the
--    line readable after that.
CREATE TABLE IF NOT EXISTS procedure_bill_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    procedure_bill_id   INT NOT NULL,
    procedure_master_id INT NULL,
    description         VARCHAR(150) NOT NULL,
    quantity            INT NOT NULL DEFAULT 1,
    unit_rate           DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Post-discount line amount; SUM(amount) = the bill's grand_total.
    amount              DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- ---- Share snapshot, copied from doctor_procedures at billing time ----
    doctor_share_pct    DECIMAL(5,2) NOT NULL DEFAULT 0,
    has_tax             TINYINT      NOT NULL DEFAULT 0,
    tax_percent         DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pbi_bill (procedure_bill_id),
    FOREIGN KEY (procedure_bill_id)   REFERENCES procedure_bills(id) ON DELETE CASCADE,
    FOREIGN KEY (procedure_master_id) REFERENCES procedure_master(id) ON DELETE SET NULL
);

-- 4. Permission to raise a procedure bill. Separate key from the ER one so the
--    two counters can be staffed independently. Granted to ADMIN and MANAGER
--    below; assign it to reception users from Settings > Permissions.
-- NB: the column is `key` (a reserved word — always backticked) and the join
-- table keys on base_role, not role.
INSERT IGNORE INTO permissions (`key`, label, category)
VALUES ('RECEPTION_RAISE_PROCEDURE_BILL', 'Raise procedure bills', 'reception');

-- Grant to every role preset that already holds the ER-bill key, so whoever can
-- raise an ER bill can raise a procedure bill. Guarded by INSERT IGNORE against
-- the unique (base_role, permission_id) key, so re-running is safe.
--
-- The subquery reads the SAME table it inserts into, which MySQL refuses
-- (#1093) unless it is materialised first — hence the derived-table wrapper.
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT src.base_role, (SELECT id FROM permissions WHERE `key` = 'RECEPTION_RAISE_PROCEDURE_BILL')
FROM (
    SELECT rp.base_role
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE p.`key` = 'RECEPTION_RAISE_ER_BILL'
) AS src;

-- =============================================================================
-- End procedure-billing migration.
-- Verify with the block below. Every table is FULLY QUALIFIED on purpose: a
-- query against information_schema switches phpMyAdmin's current-database
-- context, so a following bare `FROM permissions` resolves to
-- information_schema.permissions and dies with
-- "#1109 - Unknown table 'permissions' in information_schema".
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = 'u402528120_hmis'
--      AND table_name IN ('procedure_bills','procedure_bill_items','procedure_invoice_counters');
--   -- expect 3 rows
--
--   SELECT `key` FROM u402528120_hmis.permissions
--    WHERE `key` = 'RECEPTION_RAISE_PROCEDURE_BILL';
--   -- expect 1 row
--
--   SELECT rp.base_role
--     FROM u402528120_hmis.role_permissions rp
--     JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
--    WHERE p.`key` = 'RECEPTION_RAISE_PROCEDURE_BILL';
--   -- expect one row per role that already held RECEPTION_RAISE_ER_BILL
-- =============================================================================
