-- Phase 2: patients + visits core loop. Depends on sql/add_locations.sql and
-- sql/add_doctor_consult_types.sql being applied first (FKs to cities/areas/doctor_consult_types).

CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Nullable + UNIQUE (not NOT NULL): MySQL allows multiple NULLs under a UNIQUE index, so the
    -- insert-then-derive-from-id pattern (see patients.php) can briefly hold a NULL mrn without
    -- colliding with other concurrent inserts, then update it to the real value right after.
    mrn VARCHAR(20) NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    father_name VARCHAR(150),
    dob DATE NULL,
    approx_age INT NULL,
    gender ENUM('MALE','FEMALE','OTHER') NOT NULL,
    phone VARCHAR(30) NOT NULL,
    alt_phone VARCHAR(30) NULL,
    cnic VARCHAR(20) NULL,
    city_id INT NULL,
    area_id INT NULL,
    address VARCHAR(255) NULL,
    created_by_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (city_id) REFERENCES cities(id),
    FOREIGN KEY (area_id) REFERENCES areas(id),
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    INDEX idx_patient_search (name, phone, father_name)
);

CREATE TABLE IF NOT EXISTS visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_no INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    doctor_consult_type_id INT NOT NULL,
    fee DECIMAL(10,2) NOT NULL,
    discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    discount_applied_by_id INT NULL,
    payment_mode ENUM('CASH','DIGITAL') NOT NULL,
    disposition ENUM('OPD','SHORT_STAY') NULL,
    visit_date DATE NOT NULL,
    created_by_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (doctor_consult_type_id) REFERENCES doctor_consult_types(id),
    FOREIGN KEY (discount_applied_by_id) REFERENCES users(id),
    FOREIGN KEY (created_by_id) REFERENCES users(id),
    INDEX idx_visit_queue (doctor_id, visit_date, token_no)
);

-- Per-doctor, per-day queue token counter. Incremented atomically via
-- INSERT ... ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token + 1),
-- which MySQL serializes through row locking — safe under concurrent registrations,
-- unlike a read-then-insert "SELECT MAX(token_no) + 1" which can race.
CREATE TABLE IF NOT EXISTS visit_queue_counters (
    doctor_id INT NOT NULL,
    visit_date DATE NOT NULL,
    next_token INT NOT NULL DEFAULT 1,
    PRIMARY KEY (doctor_id, visit_date),
    FOREIGN KEY (doctor_id) REFERENCES users(id)
);
