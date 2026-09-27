-- Structured, queryable sibling to audit_logs for money-adjacent events.
--
-- audit_logs (sql/schema.sql) already gets a call at every invoice/discount/
-- refund/discharge write site in the app, but it's user_id + free-text
-- action/details — no patient_id, amount, invoice_number or machine-readable
-- event_type, so it can't be filtered into a list. This table is written
-- ALONGSIDE the existing audit_log() call at each site (config/billing.php's
-- log_shift_event() helper), same transaction, no new transaction boundaries.
--
-- Backs three pages: doctor "My Patients" (own INVOICE events, date range),
-- staff "My Shift Log" (own events, today's business_date only), admin
-- "Visit Log" (all events, date range + doctor + staff + stream + status).
--
-- Decisions (confirmed during planning):
--   * business_date is business_day($pdo) at write time, NOT CURDATE() — an
--     overnight shift (before the 04:00 cutoff) logs against the correct
--     business day, same as shift_closing.php's own cutoff-aware tally.
--   * status is DENORMALIZED (snapshotted at write, then kept in sync by the
--     existing void_*() helpers in config/billing.php) rather than joined
--     live per stream at read time — simpler query for Visit Log, acceptable
--     staleness risk since voids are rare and each void site gets one added
--     UPDATE here.
--   * actor_id = $_SESSION['user_id'] at write time, matching audit_log()'s
--     own convention — during impersonation this is the impersonated staff
--     member, not the admin, so a shift log reconciles against what that
--     staff member sees on their own screen (same reasoning as day-cash
--     tallies already use).
--   * Roles are the current 3-role model (ADMIN/DOCTOR/STAFF) — NOT the
--     legacy 4-role names some older migrations still reference.
--
-- Depends on: sql/schema.sql (users, permissions), sql/add_patients.sql
-- (patients, visits), sql/run_now_billing_no_tax.sql (bills).
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS shift_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type ENUM('INVOICE','DISCOUNT','REFUND','DISCHARGE','WRITEOFF') NOT NULL,
    stream ENUM('OPD','ER','IPD','PROCEDURE','DENTAL') NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NULL,
    actor_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    reference_type VARCHAR(20) NOT NULL,
    reference_id INT NOT NULL,
    invoice_number VARCHAR(30) NULL,
    status ENUM('paid','pending','voided','partial') NOT NULL DEFAULT 'paid',
    business_date DATE NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    INDEX idx_shift_events_actor_day (actor_id, business_date),
    INDEX idx_shift_events_doctor_day (doctor_id, business_date),
    INDEX idx_shift_events_patient (patient_id),
    INDEX idx_shift_events_day_type (business_date, event_type)
);

INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'DOCTOR_VIEW_OWN_VISITS' AS `key`,
           'View own patient/visit history (My Patients)' AS label,
           'clinical' AS category
    UNION ALL
    SELECT 'RECEPTION_VIEW_OWN_SHIFT_LOG',
           'View own invoice/discount/refund/discharge activity for today (My Shift Log)',
           'reception'
    UNION ALL
    SELECT 'FINANCIAL_VIEW_VISIT_LOG',
           'View the clinic-wide visit/invoice log (admin Visit Log)',
           'financial'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- DOCTOR_VIEW_OWN_VISITS: DOCTOR (and ADMIN, who holds everything) only —
-- this is explicitly "MY patients", not a cross-doctor report.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` = 'DOCTOR_VIEW_OWN_VISITS'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- RECEPTION_VIEW_OWN_SHIFT_LOG: STAFF (and ADMIN) — folded into the broad
-- STAFF union per project_hmis_three_roles, not a narrow per-user grant.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'STAFF') r
JOIN permissions p ON p.`key` = 'RECEPTION_VIEW_OWN_SHIFT_LOG'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- FINANCIAL_VIEW_VISIT_LOG: ADMIN only — sits in the admin-only Analytics
-- group alongside income/expense/discount reports.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role) r
JOIN permissions p ON p.`key` = 'FINANCIAL_VIEW_VISIT_LOG'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
