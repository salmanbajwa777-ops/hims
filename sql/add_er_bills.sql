-- =============================================================================
-- ER Service walk-in billing (2026-07-25).
--
-- A lightweight, PREPAID path for an ultra-short ER touchpoint — patient comes
-- in, gets an injection / nebulization / dressing, pays, and leaves. This is
-- deliberately NOT an admission: no admitted_at clock, no room/stay charge, no
-- open stay to dangle, no discharge step. Reception picks service(s) from the
-- existing er_services_master catalogue against a REGISTERED patient and raises
-- + settles the bill in one step.
--
-- Modeled exactly on the admission_bills pattern (own document table + own
-- counter, folded into day_cash_tally by paid_by_id). Own "E" invoice series so
-- it never collides with the consult (digit-only), admission ("A") or IPD series.
-- Line items snapshot the service name + rate so later catalogue edits never
-- rewrite history (same reasoning as admission_services).
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT ... WHERE NOT EXISTS for the
-- permission. Run in phpMyAdmin against the HMIS database. Safe to re-run.
-- RUN BEFORE deploying the code (er_bill.php INSERTs need these tables).
-- =============================================================================

-- ---- Monthly counter for the "E" invoice series ----
CREATE TABLE IF NOT EXISTS er_invoice_counters (
    yr INT NOT NULL,
    mo INT NOT NULL,
    next_seq INT NOT NULL,
    PRIMARY KEY (yr, mo)
);

-- ---- The ER bill: one document, tied directly to a patient (no visit/doctor) ----
CREATE TABLE IF NOT EXISTS er_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,     -- own series, "E" prefix (see billing.php)
    patient_id INT NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Always prepaid at creation: 'paid' when money moved, 'waived' if a full
    -- discount brought the total to zero. No draft/finalized limbo.
    status ENUM('paid','waived','voided') NOT NULL DEFAULT 'paid',
    payment_method ENUM('cash','card','bank_transfer','cheque') NULL,
    paid_amount DECIMAL(10,2) NULL,
    paid_at TIMESTAMP NULL,
    paid_by_id INT NULL,                            -- cashier whose drawer this hits
    created_by_id INT NOT NULL,
    printed_at TIMESTAMP NULL,
    printed_by_id INT NULL,
    -- Void trio so an ER bill reverses like every other money document.
    voided_at TIMESTAMP NULL,
    voided_by_id INT NULL,
    void_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by_id) REFERENCES users(id),
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    FOREIGN KEY (printed_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by_id) REFERENCES users(id),
    INDEX idx_er_bill_patient (patient_id),
    INDEX idx_er_bill_paid (paid_by_id, paid_at)
);

-- ---- Line items: one row per service on the bill, price snapshotted ----
CREATE TABLE IF NOT EXISTS er_bill_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    er_bill_id INT NOT NULL,
    er_service_id INT NULL,                         -- source catalogue row (nullable, historical)
    description VARCHAR(200) NOT NULL,              -- snapshot of service_name
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1,
    unit_rate DECIMAL(10,2) NOT NULL,              -- snapshot of base_charge at sale time
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (er_bill_id) REFERENCES er_bills(id) ON DELETE CASCADE,
    FOREIGN KEY (er_service_id) REFERENCES er_services_master(id) ON DELETE SET NULL,
    INDEX idx_er_item_bill (er_bill_id)
);

-- ---- Permission: RECEPTION_RAISE_ER_BILL (reception category, admin-assignable) ----
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'RECEPTION_RAISE_ER_BILL' AS `key`,
           'Raise a walk-in ER service bill' AS label,
           'reception' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Grant to STAFF (reception) + ADMIN. Per-user grants managed on Staff & Doctors.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'STAFF' AS base_role UNION ALL SELECT 'ADMIN') r
JOIN permissions p ON p.`key` = 'RECEPTION_RAISE_ER_BILL'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
