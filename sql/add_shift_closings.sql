-- Day closing / cash tally / handover (approved mock 2026-07-23).
--
-- Flow: receptionist counts the drawer by denomination, declares the cash
-- handed to admin, submits → the day's payments LOCK, an A5 closing slip
-- (DC-YYYY-NNNN) prints for wet signatures, and the handover queues in the
-- admin portal. Admin recounts, ticks "signed slip filed", marks received.
--
-- Decisions:
--   * One closing per calendar date (closing_date UNIQUE) — the whole
--     reception day closes at once, not per-cashier shifts.
--   * The float (default Rs 5,000) stays in the drawer for the next day and
--     is snapshotted per closing so a float change never rewrites history.
--   * Expected cash = float + cash payments (consult bills + admission bills,
--     by DATE(paid_at)) − cash refunds (by DATE(created_at)). Online is
--     verified against the bank, never counted.
--   * After a date has a closing row, payment/refund actions that day are
--     refused (see require_day_open() in config/billing.php) so the signed
--     tally can never drift; late activity is recorded the next day.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + WHERE NOT EXISTS seeds — safe to
-- paste into phpMyAdmin more than once.
-- Depends on: sql/schema.sql (users, permissions), run_now_billing_no_tax.sql.

-- Yearly DC- slip number series, same shape as refund_sequences.
CREATE TABLE IF NOT EXISTS closing_sequences (
    sequence_year SMALLINT NOT NULL PRIMARY KEY,
    last_sequence INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS shift_closings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    closing_number VARCHAR(30) UNIQUE NOT NULL,           -- DC-2026-0187
    closing_date DATE NOT NULL UNIQUE,                    -- one closing per day
    cashier_id INT NOT NULL,                              -- receptionist who closed

    -- System-side tally, snapshotted at closing time so the slip reprints
    -- identically even if data is later corrected.
    opening_float DECIMAL(10,2) NOT NULL DEFAULT 0,
    cash_consult_total DECIMAL(10,2) NOT NULL DEFAULT 0,  cash_consult_count INT NOT NULL DEFAULT 0,
    cash_admission_total DECIMAL(10,2) NOT NULL DEFAULT 0, cash_admission_count INT NOT NULL DEFAULT 0,
    online_total DECIMAL(10,2) NOT NULL DEFAULT 0,        online_count INT NOT NULL DEFAULT 0,
    cash_refund_total DECIMAL(10,2) NOT NULL DEFAULT 0,   cash_refund_count INT NOT NULL DEFAULT 0,
    expected_cash DECIMAL(10,2) NOT NULL DEFAULT 0,       -- float + cash in − cash refunds

    -- Human-side count.
    counted_cash DECIMAL(10,2) NOT NULL DEFAULT 0,        -- from the denomination rows
    variance DECIMAL(10,2) NOT NULL DEFAULT 0,            -- counted − expected (negative = short)
    variance_note VARCHAR(255) NULL,                      -- required when variance ≠ 0

    -- Handover: what reception DECLARED passing to admin, and what admin
    -- actually RECEIVED on recount. A mismatch is a handover discrepancy,
    -- separate from the drawer variance above.
    float_retained DECIMAL(10,2) NOT NULL DEFAULT 0,
    handover_declared DECIMAL(10,2) NOT NULL DEFAULT 0,
    handover_to_id INT NOT NULL,                          -- the admin named at closing
    handover_received DECIMAL(10,2) NULL,                 -- admin's recount
    received_by_id INT NULL,                              -- admin who acknowledged
    received_at TIMESTAMP NULL,
    slip_filed TINYINT(1) NOT NULL DEFAULT 0,             -- signed paper copy in the audit file

    status ENUM('PENDING_RECEIPT','RECEIVED') NOT NULL DEFAULT 'PENDING_RECEIPT',
    printed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (handover_to_id) REFERENCES users(id),
    FOREIGN KEY (received_by_id) REFERENCES users(id),
    INDEX idx_closing_status (status)
);

-- The physical count, one row per note face. face_value 1 with qty = the
-- rupee amount is how loose coins are stored (the UI's "Coins" line).
CREATE TABLE IF NOT EXISTS shift_closing_denominations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    closing_id INT NOT NULL,
    face_value INT NOT NULL,                              -- 5000/1000/500/100/50/20/10/1
    qty INT NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,              -- face_value × qty (coins: qty itself)
    FOREIGN KEY (closing_id) REFERENCES shift_closings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_closing_face (closing_id, face_value)
);

-- The float amount lives in clinic_settings so admin can change it without a
-- deploy; each closing snapshots the value in force that day.
INSERT INTO clinic_settings (setting_key, setting_value)
SELECT * FROM (SELECT 'opening_float' AS setting_key, '5000' AS setting_value) AS seed
WHERE NOT EXISTS (SELECT 1 FROM clinic_settings s WHERE s.setting_key = 'opening_float');

-- Permissions: reception closes the day; admin receives the cash.
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'RECEPTION_CLOSE_DAY' AS `key`,
           'Run the day closing & cash handover' AS label,
           'financial' AS category
    UNION ALL
    SELECT 'ADMIN_RECEIVE_HANDOVER',
           'Acknowledge cash handovers from reception',
           'financial'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'ADMIN' AS base_role, 'RECEPTION_CLOSE_DAY' AS pkey
    UNION ALL SELECT 'RECEPTIONIST', 'RECEPTION_CLOSE_DAY'
    UNION ALL SELECT 'ADMIN', 'ADMIN_RECEIVE_HANDOVER'
) r
JOIN permissions p ON p.`key` = r.pkey
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
