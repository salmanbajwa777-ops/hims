-- =============================================================================
-- Lab results module (2026-11-11)  --  Metacare Diagnostics report printouts
--
-- WHAT THIS IS
-- Admin keys in (or uploads) a patient's lab results and prints a Metacare
-- Diagnostics report. The patient pays the LAB directly, so NOTHING here touches
-- clinic income, Day Closing, handovers, doctor share or analytics. There is
-- deliberately no money column anywhere in this file. (Generate Bill is a later
-- step and will get its own table.)
--
-- SNAPSHOT RULE
-- lab_results copies test name, section, parameter name, unit and reference range
-- onto each row at save time. Editing the catalogue later therefore never changes
-- a report that was already issued.
--
-- Flat and idempotent; no stored procedures (#1044); no ENGINE/CHARSET clause
-- (forcing one mismatched patients/users before and made FKs fail, errno 150).
-- All table names lowercase (case-sensitive host).
-- RUN ORDER: this file, then seed_lab_tests.sql.
-- =============================================================================

CREATE TABLE IF NOT EXISTS lab_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section VARCHAR(80) NOT NULL,          -- Hematology / Clinical Chemistry / Immunology
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_lab_test_name (name)
);

CREATE TABLE IF NOT EXISTS lab_test_params (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    group_label VARCHAR(80) NOT NULL DEFAULT '',   -- sub-heading inside a panel (DLC, Absolute DLC)
    name VARCHAR(150) NOT NULL,
    unit VARCHAR(40) NOT NULL DEFAULT '',
    ref_range VARCHAR(255) NOT NULL DEFAULT '',    -- "4500-13500" or free text "Negative: 0 - 5 | Positive: >5"
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_lab_param (test_id, name),
    FOREIGN KEY (test_id) REFERENCES lab_tests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lab_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NULL,                           -- NULL = walk-in, typed details below
    walkin_name VARCHAR(150) NULL,
    walkin_age VARCHAR(40) NULL,
    walkin_gender VARCHAR(10) NULL,
    walkin_phone VARCHAR(30) NULL,
    referred_by VARCHAR(150) NULL,
    ordered_by_id INT NULL,                        -- doctor, when ordered by one (later); NULL = admin-created
    specimen_at DATETIME NULL,
    report_at DATETIME NULL,
    note TEXT NULL,
    created_by_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    INDEX idx_lab_order_patient (patient_id),
    INDEX idx_lab_order_created (created_at)
);

CREATE TABLE IF NOT EXISTS lab_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    test_id INT NULL,                              -- NULL-safe: snapshot below is the truth
    test_name VARCHAR(150) NOT NULL,
    section VARCHAR(80) NOT NULL,
    group_label VARCHAR(80) NOT NULL DEFAULT '',
    param_name VARCHAR(150) NOT NULL,
    unit VARCHAR(40) NOT NULL DEFAULT '',
    ref_range VARCHAR(255) NOT NULL DEFAULT '',
    result_value VARCHAR(100) NOT NULL DEFAULT '',
    interp VARCHAR(60) NOT NULL DEFAULT '',        -- printed under the value: Positive / Negative
    is_out TINYINT(1) NOT NULL DEFAULT 0,          -- numeric value outside a numeric range
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (order_id) REFERENCES lab_orders(id) ON DELETE CASCADE,
    INDEX idx_lab_result_order (order_id, sort_order)
);

CREATE TABLE IF NOT EXISTS lab_result_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    original_name VARCHAR(200) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    size_bytes INT NOT NULL DEFAULT 0,
    uploaded_by_id INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES lab_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by_id) REFERENCES users(id)
);
