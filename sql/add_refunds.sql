-- Refunds against paid invoices.
--
-- Decisions (confirmed 2026-07-21):
--   * Separate RF- numbering series, independent of the invoice sequence.
--   * Partial refunds allowed — several against one bill, capped in total by the
--     amount actually paid. The cap is SUM(refunds.amount) per bill, enforced in
--     PHP inside a transaction (MySQL cannot express a cross-row CHECK).
--   * "Approved by" must be the doctor on that visit; the app constrains the
--     dropdown to visits.doctor_id rather than offering every doctor.
--
-- Depends on sql/run_now_billing_no_tax.sql (bills table) being applied first.
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS refund_sequences (
    sequence_year SMALLINT NOT NULL PRIMARY KEY,
    last_sequence INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    refund_number VARCHAR(30) UNIQUE NOT NULL,
    bill_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(60) NOT NULL,
    notes VARCHAR(255) NULL,
    refund_mode ENUM('cash','card','bank_transfer') NOT NULL DEFAULT 'cash',
    -- approved_by_id is the doctor on the visit; generated_by_id is whoever was
    -- logged in (doctor, admin or receptionist). "Received by" is the patient and
    -- is captured as a wet signature on the printed voucher, so it has no column.
    approved_by_id INT NOT NULL,
    generated_by_id INT NOT NULL,
    printed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by_id) REFERENCES users(id),
    FOREIGN KEY (generated_by_id) REFERENCES users(id),
    INDEX idx_refund_bill (bill_id)
);

INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'RECEPTION_ISSUE_REFUNDS' AS `key`,
           'Issue refunds against paid invoices' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Receptionists can refund without escalation (confirmed); doctors and admin too.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'RECEPTIONIST' UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` = 'RECEPTION_ISSUE_REFUNDS'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
