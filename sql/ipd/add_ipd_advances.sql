-- =============================================================================
-- IPD advance payments (2026-07-28).
--
-- Money taken from an In-Door patient BEFORE the stay is billed. A patient may
-- pay several advances across a long admission, so this is a ledger of receipts
-- rather than a column on the admission: one row per payment, each attributed to
-- the drawer that took it.
--
-- Why its own table and not ipd_bills.paid_amount:
--   - advances arrive before ipd_bills exists (that row is created at discharge)
--   - there can be MANY per admission; paid_amount is a single settlement figure
--   - each receipt needs its own paid_by_id/paid_at so it lands on the correct
--     shift's cash tally the moment it is taken (see day_cash_tally)
--
-- At discharge the advance total is deducted from the bill. If advances EXCEED
-- the final bill the excess is refunded from the drawer, recorded here as a
-- negative-direction row (direction='REFUND') so the ledger stays the single
-- source of truth for "what has this patient paid us, net".
--
-- The refunds table is NOT reused: refunds.bill_id is NOT NULL with a foreign
-- key to `bills` (consultation bills only), so an IPD advance refund cannot be
-- represented there.
--
-- No stored procedures — the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- No ENGINE/CHARSET clause: it must match the tables it references, and forcing
-- utf8mb4 has silently aborted a migration here before (errno 150).
-- Idempotent: safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ipd_advances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(30) UNIQUE NOT NULL,
    admission_id INT NOT NULL,
    -- PAYMENT = money in (an advance). REFUND = money back out at discharge when
    -- the advances exceeded the final bill. Both are stored positive; direction
    -- carries the sign, so SUM() needs a CASE and can never be mis-signed by an
    -- accidental negative amount.
    direction ENUM('PAYMENT','REFUND') NOT NULL DEFAULT 'PAYMENT',
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','card','bank_transfer','cheque') NOT NULL DEFAULT 'cash',
    note VARCHAR(255) NULL,
    -- Drawer attribution, exactly as every other money table: the shift that
    -- physically handled the cash owns it.
    paid_at TIMESTAMP NULL,
    paid_by_id INT NULL,
    received_by_id INT NOT NULL,
    printed_at TIMESTAMP NULL,
    printed_by_id INT NULL,
    voided_at TIMESTAMP NULL,
    voided_by_id INT NULL,
    void_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ipd_adv_admission (admission_id, voided_at),
    KEY idx_ipd_adv_drawer (paid_by_id, paid_at, voided_at),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by_id) REFERENCES users(id),
    FOREIGN KEY (paid_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (printed_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Yearly counter for the "ADV-YYYY-NNNN" receipt series. Its own series so it
-- never collides with the "I" (IPD bill), "A" (admission), "E" (ER), "P"
-- (procedure) or RF- (refund) series.
CREATE TABLE IF NOT EXISTS ipd_advance_counters (
    yr INT NOT NULL,
    last_no INT NOT NULL DEFAULT 0,
    PRIMARY KEY (yr)
);

-- Snapshot of what was settled against the bill at discharge, so the invoice can
-- show "less advances paid" without recomputing a historical sum that later
-- voids would change.
--
-- Run these two ALTERs. MySQL has no ADD COLUMN IF NOT EXISTS and this DB user
-- cannot CREATE PROCEDURE (#1044), so there is no way to guard them in plain
-- SQL. On a RE-RUN each returns "#1060 Duplicate column name" — that error is
-- EXPECTED and harmless; it means the column is already there. Every other
-- statement in this file is genuinely idempotent.
ALTER TABLE ipd_bills ADD COLUMN advance_applied DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE ipd_bills ADD COLUMN advance_refunded DECIMAL(10,2) NOT NULL DEFAULT 0;

-- Permission: taking an advance is a reception cash action, distinct from
-- raising the discharge bill.
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'RECEPTION_TAKE_IPD_ADVANCE' AS `key`,
           'Take an IPD advance payment' AS label,
           'reception' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Grant to STAFF (reception) + ADMIN. Doctors do not handle cash.
INSERT INTO role_permissions (base_role, permission_id)
SELECT seed.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF') AS seed
JOIN permissions p ON p.`key` = 'RECEPTION_TAKE_IPD_ADVANCE'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = seed.base_role AND rp.permission_id = p.id
);
