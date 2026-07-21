-- ============================================================================
-- RUN THIS IN phpMyAdmin (database: your HMIS schema)
--
-- Covers everything needed for: register -> auto-invoice -> print, with NO tax.
-- Safe to run whether or not sql/add_billing.sql was applied before: every
-- statement is idempotent, so re-running it changes nothing further.
--
-- Depends on sql/add_patients.sql (visits/patients tables) already being applied.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. Billing tables
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS clinic_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value VARCHAR(100) NOT NULL
);

-- Daily invoice number sequence, base 94345 (matches the printed-invoice format
-- from the original spec: "94345 - 2026-07-17 14:03:00").
CREATE TABLE IF NOT EXISTS invoice_sequences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sequence_date DATE NOT NULL UNIQUE,
    last_sequence INT NOT NULL DEFAULT 94344
);

-- The sales_tax_* / consolidation_* columns are kept but always written as 0.
-- They default to 0 here so no code path can fail on a NOT NULL with no default.
CREATE TABLE IF NOT EXISTS bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    visit_id INT NOT NULL UNIQUE,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    sales_tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    sales_tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    consolidation_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    consolidation_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('draft','finalized','paid') NOT NULL DEFAULT 'draft',
    payment_method ENUM('cash','card','bank_transfer','cheque') NULL,
    paid_amount DECIMAL(10,2) NULL,
    paid_at TIMESTAMP NULL,
    created_by_id INT NOT NULL,
    printed_at TIMESTAMP NULL,
    printed_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    FOREIGN KEY (printed_by_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS bill_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id INT NOT NULL,
    description VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
);


-- ---------------------------------------------------------------------------
-- 2. users.specialty — drives which logo prints (baby-face vs. tooth).
--    Added conditionally: MySQL has no "ADD COLUMN IF NOT EXISTS", so this
--    checks information_schema first and skips if the column already exists.
-- ---------------------------------------------------------------------------

SET @has_specialty := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'specialty'
);
SET @ddl := IF(@has_specialty = 0,
    "ALTER TABLE users ADD COLUMN specialty ENUM('GENERAL','DENTAL') NOT NULL DEFAULT 'GENERAL'",
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3. No tax on invoices.
--    The app no longer reads these settings or computes tax; clearing them keeps
--    the table honest if add_billing.sql previously seeded 17% / 2%.
-- ---------------------------------------------------------------------------

DELETE FROM clinic_settings
WHERE setting_key IN ('sales_tax_percent', 'consolidation_rate_percent');

-- Flatten existing UNPAID bills so Net Total == sum of line items on reprint.
-- Paid bills are deliberately left alone: paid_amount records what the patient
-- actually handed over, and rewriting grand_total would make a reprint disagree
-- with the money collected.
UPDATE bills
SET sales_tax_percent = 0,
    sales_tax_amount = 0,
    consolidation_rate_percent = 0,
    consolidation_amount = 0,
    grand_total = subtotal
WHERE status <> 'paid';


-- ---------------------------------------------------------------------------
-- 4. Verify (optional — run separately to eyeball the result)
-- ---------------------------------------------------------------------------
-- SELECT setting_key, setting_value FROM clinic_settings;
-- SELECT id, invoice_number, subtotal, grand_total, status FROM bills ORDER BY id DESC LIMIT 10;
