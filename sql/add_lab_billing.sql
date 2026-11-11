-- =============================================================================
-- Lab billing (2026-11-11)  --  Metacare Diagnostics bill / invoice
--
-- WHAT THIS IS
-- The clinic issues the invoice and takes payment ON BEHALF OF the lab. None of
-- it is clinic income: nothing here is read by Day Closing, Visit Log, doctor
-- share, income reports or analytics. The money columns live ONLY in these two
-- tables, on purpose, so they can never be summed into clinic income by accident.
--
-- NO STORED TOTALS
-- Total = SUM(lab_invoice_lines.amount); Net = Total - adjustment;
-- Balance due = Net - received. All three are computed at read/print time.
-- Only adjustment and received are stored (they are typed facts).
--
-- INVOICE NUMBER
-- Printed as MCD-<MM>-<NNNN>, resets each month, e.g. MCD-11-0001.
-- The counter is keyed by YYMM so the database never collides; the printed
-- number repeats a year later (Nov 2026 and Nov 2027 both start MCD-11-0001).
-- Hence invoice_no is NOT unique; (period_ym, seq) is.
-- Allocate the next number inside the same transaction as the invoice insert:
--   INSERT INTO lab_invoice_counters (period_ym, last_seq) VALUES (?, 1)
--     ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1);
--   then seq = (the inserted 1) or SELECT LAST_INSERT_ID() after the update.
--
-- Flat and idempotent; no stored procedures (#1044); no ENGINE/CHARSET clause
-- (forcing one mismatched patients/users before and made FKs fail, errno 150).
-- All table names lowercase (case-sensitive host).
-- RUN ORDER: add_lab_module.sql first (needs lab_orders), then this file.
-- =============================================================================

CREATE TABLE IF NOT EXISTS lab_invoice_counters (
    period_ym CHAR(4) NOT NULL PRIMARY KEY,        -- '2611' = Nov 2026
    last_seq INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS lab_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,                         -- one invoice per lab order
    period_ym CHAR(4) NOT NULL,
    seq INT NOT NULL,
    invoice_no VARCHAR(20) NOT NULL,               -- MCD-11-0001, stored so reprints never change
    adjustment DECIMAL(10,2) NOT NULL DEFAULT 0,   -- discount; Net = Total - adjustment
    received DECIMAL(10,2) NOT NULL DEFAULT 0,     -- paid so far; Balance = Net - received
    created_by_id INT NOT NULL,                    -- "Initial By" on the print
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,-- "Init. Time" on the print
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_lab_invoice_order (order_id),
    UNIQUE KEY uq_lab_invoice_seq (period_ym, seq),
    INDEX idx_lab_invoice_no (invoice_no),
    INDEX idx_lab_invoice_created (created_at),
    FOREIGN KEY (order_id) REFERENCES lab_orders(id),
    FOREIGN KEY (created_by_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS lab_invoice_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    test_id INT NULL,                              -- catalogue pick; NULL for free-typed. Snapshot below is the truth
    title VARCHAR(150) NOT NULL,
    process VARCHAR(20) NOT NULL DEFAULT 'Normal', -- Normal / Urgent, label only
    reporting_at DATETIME NULL,
    sample_no VARCHAR(30) NULL,                    -- lines with the same value = one specimen
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,       -- typed per bill
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES lab_invoices(id) ON DELETE CASCADE,
    INDEX idx_lab_line_invoice (invoice_id, sort_order)
);
