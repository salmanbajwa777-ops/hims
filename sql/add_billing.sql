-- Phase 4 (partial): manual-entry billing/checkout, ahead of procedures (Phase 3) and
-- staff commission (Phase 3A). Line items are free-entry for now, not sourced from
-- procedure_master — see HMIS-PHP-PLAN.md §5 for the full dependency chain this jumps ahead of.
-- Depends on sql/add_patients.sql (visits table) being applied first.

CREATE TABLE IF NOT EXISTS clinic_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value VARCHAR(100) NOT NULL
);
-- No tax settings are seeded: invoices carry no sales tax or consolidation rate.
-- See sql/run_now_billing_no_tax.sql, which is the migration actually applied.

-- Daily invoice number sequence, base 94345 (matches the printed-invoice-number format
-- from the original spec: "94345 - 2026-07-17 14:03:00").
CREATE TABLE IF NOT EXISTS invoice_sequences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sequence_date DATE NOT NULL UNIQUE,
    last_sequence INT NOT NULL DEFAULT 94344
);

CREATE TABLE IF NOT EXISTS bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    visit_id INT NOT NULL UNIQUE,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    sales_tax_percent DECIMAL(5,2) NOT NULL,
    sales_tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    consolidation_rate_percent DECIMAL(5,2) NOT NULL,
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

-- Drives which invoice icon prints (baby-face vs. tooth) once a dentist is added as staff.
ALTER TABLE users
  ADD COLUMN specialty ENUM('GENERAL','DENTAL') NOT NULL DEFAULT 'GENERAL';
