-- =============================================================================
-- IPD (In-Door) — Phase 4: discharge + manual discharge summary + billing.
-- Own invoice series ("I" prefix), separate from consultation bills and ER
-- admission_bills. Depends on Phases 1-3. Idempotent. Run LAST.
-- All timestamps are PKT (+05:00).
-- =============================================================================

-- 1. ipd_invoice_counters — own high-water-mark series.
CREATE TABLE IF NOT EXISTS ipd_invoice_counters (
    yr INT NOT NULL,
    mo INT NOT NULL,
    next_seq INT NOT NULL,
    PRIMARY KEY (yr, mo)
);

-- 2. ipd_bills — the IPD invoice (own "I" series).
CREATE TABLE IF NOT EXISTS ipd_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    admission_id INT NOT NULL UNIQUE,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    manual_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    manual_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('draft','finalized','paid') NOT NULL DEFAULT 'draft',
    payment_method ENUM('cash','card','bank_transfer','cheque') NULL,
    paid_amount DECIMAL(10,2) NULL,
    write_off_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_at TIMESTAMP NULL,
    paid_by_id INT NULL,
    created_by_id INT NOT NULL,
    finalized_by_id INT NULL,
    printed_at TIMESTAMP NULL,
    printed_by_id INT NULL,
    voided_at TIMESTAMP NULL,
    voided_by_id INT NULL,
    void_reason VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    FOREIGN KEY (finalized_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (paid_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (printed_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 3. ipd_bill_items — STAY (per-day bed) / CONSULT_VISIT (daily fees) / SERVICE.
CREATE TABLE IF NOT EXISTS ipd_bill_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ipd_bill_id INT NOT NULL,
    description VARCHAR(200) NOT NULL,
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1,
    unit_rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    item_kind ENUM('STAY','CONSULT_VISIT','SERVICE') NOT NULL DEFAULT 'SERVICE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ipd_bill_item (ipd_bill_id),
    FOREIGN KEY (ipd_bill_id) REFERENCES ipd_bills(id) ON DELETE CASCADE
);

-- 4. ipd_services — chargeable services logged during the stay. References the
--    shared er_services_master catalogue (read-only), FK to ipd_admissions.
CREATE TABLE IF NOT EXISTS ipd_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    er_service_id INT NULL,
    service_type ENUM('SERVICE','PROCEDURE') NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    charge_type ENUM('FLAT','HOURLY','PER_UNIT') NOT NULL DEFAULT 'FLAT',
    quantity INT NOT NULL DEFAULT 1,
    duration_minutes INT NULL,
    unit_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    calculated_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_billable TINYINT NOT NULL DEFAULT 1,
    clinical_note VARCHAR(200) NULL,
    logged_by_id INT NOT NULL,
    logged_by_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ipd_service_admission (admission_id),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (er_service_id) REFERENCES er_services_master(id) ON DELETE SET NULL,
    FOREIGN KEY (logged_by_id) REFERENCES users(id)
);

-- 5. ipd_discharge_summaries — MANUAL, author-written (no AI). Final Diagnosis
--    pre-filled from the latest ward-round note's Primary Diagnosis.
CREATE TABLE IF NOT EXISTS ipd_discharge_summaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL UNIQUE,
    final_diagnosis VARCHAR(500) NULL,
    summary_text MEDIUMTEXT NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    written_by_id INT NULL,
    finalized_by_id INT NULL,
    generated_at TIMESTAMP NULL,
    finalized_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (written_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (finalized_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 6. Permissions.
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'IPD_LOG_SERVICES'    AS `key`, 'Log chargeable IPD services'            AS label, 'admin'     AS category
    UNION ALL SELECT 'IPD_DISCHARGE_PATIENT', 'Submit / finalize an IPD discharge',           'admin'
    UNION ALL SELECT 'IPD_WRITE_SUMMARY',     'Write the IPD discharge summary',              'admin'
    UNION ALL SELECT 'IPD_FINALIZE_BILL',     'Finalize + settle an IPD bill',               'financial'
    UNION ALL SELECT 'IPD_APPROVE_WRITEOFF',  'Approve an IPD unpaid write-off',             'financial'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Services logging + discharge + bill finalize -> admin + staff (reception/manager are STAFF).
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF') r
JOIN permissions p ON p.`key` IN ('IPD_LOG_SERVICES','IPD_DISCHARGE_PATIENT','IPD_FINALIZE_BILL')
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- Discharge summary is CLINICAL -> doctor + admin by role. Admin may grant it to
-- a specific staffer per case via the per-user override system.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` = 'IPD_WRITE_SUMMARY'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- Write-off approval -> admin only.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role) r
JOIN permissions p ON p.`key` = 'IPD_APPROVE_WRITEOFF'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- =============================================================================
-- End IPD Phase 4 migration.
-- =============================================================================
