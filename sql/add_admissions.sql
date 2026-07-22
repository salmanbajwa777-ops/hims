-- =============================================================================
-- Phase 1 — Admission & Discharge
-- Reconciled to the live HIMS: extends bills/audit_logs, reuses NURSING_*
-- permissions, adds only what's genuinely new. See hims-PHASE-1-admission-
-- RECONCILED.md for the decisions behind every line.
--
-- Idempotent: uses CREATE TABLE IF NOT EXISTS and INSERT ... WHERE NOT EXISTS,
-- and guards each ALTER so re-running is safe (MySQL has no ADD COLUMN IF NOT
-- EXISTS on shared hosting, so the ALTERs are wrapped in a stored procedure).
-- Run in phpMyAdmin against the hims database.
-- All timestamps are Pakistan Standard Time (the DB session is pinned +05:00).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. admission_rates — admin-editable per-type prices (not hardcoded in code)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admission_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_type ENUM('ROUTINE','PRIVATE','LONG_PRIVATE') NOT NULL UNIQUE,
    rate_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    rate_basis ENUM('HOURLY','DAILY') NOT NULL DEFAULT 'HOURLY',
    is_enabled TINYINT NOT NULL DEFAULT 1,
    updated_by_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Seed the three types with the agreed starting rates. LONG_PRIVATE ships
-- disabled (placeholder). Admin edits these on the rates screen.
INSERT INTO admission_rates (admission_type, rate_amount, rate_basis, is_enabled)
SELECT * FROM (
    SELECT 'ROUTINE'      AS admission_type,   800.00 AS rate_amount, 'HOURLY' AS rate_basis, 1 AS is_enabled
    UNION ALL SELECT 'PRIVATE',               1200.00,               'HOURLY', 1
    UNION ALL SELECT 'LONG_PRIVATE',         14000.00,               'DAILY',  0
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM admission_rates r WHERE r.admission_type = seed.admission_type);

-- -----------------------------------------------------------------------------
-- 2. er_services_master — admin-managed catalogue ("the template")
--    Admin sets each rate and keeps adding services. Seeded at rate 0 so the
--    screen isn't empty; admin fills real rates on first use.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS er_services_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type ENUM('INJECTION_IM','INJECTION_IV','IV_DRIP','OXYGEN','PROCEDURE','OTHER') NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    charge_type ENUM('FLAT','HOURLY','PER_UNIT') NOT NULL DEFAULT 'FLAT',
    base_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_service (service_type, service_name),
    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Starter list (rate 0, admin edits). Names only; not exhaustive — admin adds more.
INSERT INTO er_services_master (service_type, service_name, charge_type, base_charge)
SELECT * FROM (
    SELECT 'INJECTION_IM' AS service_type, 'IM Injection'      AS service_name, 'PER_UNIT' AS charge_type, 0 AS base_charge
    UNION ALL SELECT 'INJECTION_IV', 'IV Injection',    'PER_UNIT', 0
    UNION ALL SELECT 'IV_DRIP',      'IV Drip',         'FLAT',     0
    UNION ALL SELECT 'OXYGEN',       'Oxygen (hourly)', 'HOURLY',   0
    UNION ALL SELECT 'PROCEDURE',    'Nebulization',    'FLAT',     0
    UNION ALL SELECT 'PROCEDURE',    'Dressing',        'FLAT',     0
    UNION ALL SELECT 'PROCEDURE',    'Catheterization', 'FLAT',     0
    UNION ALL SELECT 'PROCEDURE',    'ECG',             'FLAT',     0
    UNION ALL SELECT 'OTHER',        'Other',           'FLAT',     0
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM er_services_master m
    WHERE m.service_type = seed.service_type AND m.service_name = seed.service_name
);

-- -----------------------------------------------------------------------------
-- 3. admissions — the core lifecycle record
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL UNIQUE,
    admission_type ENUM('ROUTINE','PRIVATE','LONG_PRIVATE') NOT NULL,

    admitted_by_id INT NOT NULL,
    admitted_by_role ENUM('RECEPTIONIST','ADMIN','MANAGER','DOCTOR') NOT NULL,
    admitted_at DATETIME NOT NULL,          -- clock starts when the admit dialog is submitted

    assigned_nurse_id INT NULL,
    assigned_at DATETIME NULL,

    admitting_doctor_id INT NULL,           -- from the visit, or...
    admitting_doctor_manual VARCHAR(255) NULL,  -- ...free text if not a system user

    discharged_at DATETIME NULL,            -- when nursing submits discharge
    discharge_finalized_by_id INT NULL,     -- receptionist who took payment
    discharge_finalized_at DATETIME NULL,

    status ENUM('PENDING_ASSIGNMENT','ACTIVE','DISCHARGE_IN_PROGRESS','DISCHARGED')
        NOT NULL DEFAULT 'PENDING_ASSIGNMENT',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (admitted_by_id) REFERENCES users(id),
    FOREIGN KEY (assigned_nurse_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admitting_doctor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (discharge_finalized_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_admission_status (status),
    INDEX idx_admission_nurse (assigned_nurse_id, status)
);

-- -----------------------------------------------------------------------------
-- 4. admission_services — chargeable events during the stay (NO vitals here)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admission_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    er_service_id INT NULL,                 -- FK to master; NULL if free-typed "Other"
    service_type ENUM('INJECTION_IM','INJECTION_IV','IV_DRIP','OXYGEN','PROCEDURE','OTHER') NOT NULL,
    service_name VARCHAR(255) NOT NULL,     -- snapshot of the name at log time
    charge_type ENUM('FLAT','HOURLY','PER_UNIT') NOT NULL DEFAULT 'FLAT',

    quantity INT NOT NULL DEFAULT 1,
    duration_minutes INT NULL,              -- for HOURLY services
    unit_charge DECIMAL(10,2) NOT NULL DEFAULT 0,   -- snapshot of master rate
    calculated_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_billable TINYINT NOT NULL DEFAULT 1,

    logged_by_id INT NOT NULL,
    logged_by_role ENUM('NURSE','RECEPTIONIST','ADMIN','MANAGER') NOT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    adjusted_by_id INT NULL,                -- admin/manager edit
    adjusted_at DATETIME NULL,
    adjusted_reason VARCHAR(500) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (er_service_id) REFERENCES er_services_master(id) ON DELETE SET NULL,
    FOREIGN KEY (logged_by_id) REFERENCES users(id),
    FOREIGN KEY (adjusted_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_service_admission (admission_id)
);

-- -----------------------------------------------------------------------------
-- 5. admission_handovers — nurse-to-nurse accountability
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admission_handovers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    from_nurse_id INT NOT NULL,
    to_nurse_id INT NOT NULL,
    handover_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    status_at_handover ENUM('ACTIVE','STABLE','CRITICAL') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (from_nurse_id) REFERENCES users(id),
    FOREIGN KEY (to_nurse_id) REFERENCES users(id),
    INDEX idx_handover_admission (admission_id)
);

-- -----------------------------------------------------------------------------
-- 6. admission_bills / admission_bill_items — the SEPARATE admission invoice.
--    Mirrors the consultation bills/bill_items shape and the same draft ->
--    finalized -> paid + print-lock lifecycle, but is its own document with its
--    own invoice-number series (admission_invoice_counters). The consultation
--    `bills` table is untouched.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admission_invoice_counters (
    yr INT NOT NULL,
    mo INT NOT NULL,
    next_seq INT NOT NULL,
    PRIMARY KEY (yr, mo)
);

CREATE TABLE IF NOT EXISTS admission_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,   -- own series, "A" prefix (see billing.php)
    admission_id INT NOT NULL UNIQUE,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('draft','finalized','paid') NOT NULL DEFAULT 'draft',
    payment_method ENUM('cash','card','bank_transfer','cheque') NULL,
    paid_amount DECIMAL(10,2) NULL,               -- amount actually collected
    write_off_amount DECIMAL(10,2) NOT NULL DEFAULT 0,  -- approved unpaid shortfall (gone forever)
    paid_at TIMESTAMP NULL,
    created_by_id INT NOT NULL,
    finalized_by_id INT NULL,
    printed_at TIMESTAMP NULL,
    printed_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    FOREIGN KEY (finalized_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (printed_by_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS admission_bill_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_bill_id INT NOT NULL,
    description VARCHAR(200) NOT NULL,
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1,     -- decimal so "2.75 hours" fits
    unit_rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    item_kind ENUM('STAY','SERVICE') NOT NULL DEFAULT 'SERVICE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_bill_id) REFERENCES admission_bills(id) ON DELETE CASCADE,
    INDEX idx_abill_item (admission_bill_id)
);

-- -----------------------------------------------------------------------------
-- 7. admission_writeoffs — the "gone forever" unpaid record + patient history
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admission_writeoffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    admission_bill_id INT NULL,
    patient_id INT NOT NULL,
    amount_written_off DECIMAL(10,2) NOT NULL,
    approved_by_id INT NOT NULL,
    approved_by_role ENUM('ADMIN','MANAGER') NOT NULL,
    approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(500) NULL,
    shift_tally_date DATE NOT NULL,         -- which shift's cash this reduced
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (admission_bill_id) REFERENCES admission_bills(id) ON DELETE SET NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by_id) REFERENCES users(id),
    INDEX idx_writeoff_patient (patient_id)
);

-- -----------------------------------------------------------------------------
-- 8. Column additions to existing tables (guarded — safe to re-run)
--    MySQL on shared hosting lacks ADD COLUMN IF NOT EXISTS, so we probe
--    information_schema and only ADD when missing.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS hims_add_admission_columns;
DELIMITER $$
CREATE PROCEDURE hims_add_admission_columns()
BEGIN
    -- visits
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'admitted_at') THEN
        ALTER TABLE visits ADD COLUMN admitted_at DATETIME NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'discharged_at') THEN
        ALTER TABLE visits ADD COLUMN discharged_at DATETIME NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'admission_type') THEN
        ALTER TABLE visits ADD COLUMN admission_type ENUM('ROUTINE','PRIVATE','LONG_PRIVATE') NULL;
    END IF;

    -- NOTE: the consultation `bills` table is deliberately NOT touched. The
    -- admission bill is a SEPARATE document (admission_bills, below) with its
    -- own invoice series — a doctor advising admission after the paid OPD
    -- consultation raises a new, distinct bill.

    -- patients (unpaid rollup driving the alert badge)
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'patients' AND column_name = 'unpaid_flag') THEN
        ALTER TABLE patients ADD COLUMN unpaid_flag TINYINT NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'patients' AND column_name = 'unpaid_total') THEN
        ALTER TABLE patients ADD COLUMN unpaid_total DECIMAL(10,2) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'patients' AND column_name = 'unpaid_count') THEN
        ALTER TABLE patients ADD COLUMN unpaid_count INT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL hims_add_admission_columns();
DROP PROCEDURE IF EXISTS hims_add_admission_columns;

-- -----------------------------------------------------------------------------
-- 9. New permissions (only 2 — everything else reuses existing NURSING_*/RECEPTION_*)
-- -----------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'RECEPTION_ADMIT_PATIENTS' AS `key`,
           'Admit a patient (start a short-stay admission)' AS label,
           'admin' AS category
    UNION ALL SELECT 'ADMISSION_APPROVE_WRITEOFF',
           'Approve an unpaid admission write-off before shift close',
           'financial'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Grant RECEPTION_ADMIT_PATIENTS to reception/admin/manager.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'RECEPTIONIST' UNION ALL SELECT 'MANAGER') r
JOIN permissions p ON p.`key` = 'RECEPTION_ADMIT_PATIENTS'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- Grant ADMISSION_APPROVE_WRITEOFF to admin/manager only.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'MANAGER') r
JOIN permissions p ON p.`key` = 'ADMISSION_APPROVE_WRITEOFF'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- =============================================================================
-- End Phase 1 admission migration.
-- =============================================================================
