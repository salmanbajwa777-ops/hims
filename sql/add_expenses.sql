-- Expense module: petty-cash expenses paid out of the reception cash counter.
--
-- Decisions (2026-07-23):
--   * Receptionists (and anyone granted FINANCIAL_POST_EXPENSES) post expenses;
--     the cash comes straight out of the counter drawer.
--   * "Shift" = one calendar day (PKT) per posting user — same convention as
--     admission_writeoffs.shift_tally_date. There is no login-shift table yet;
--     expense_date is what a future shift-session concept would repoint to.
--   * Two layers of limits, both enforced in PHP at posting time:
--       - expense_categories.shift_limit  → cap per CATEGORY per shift (0 = none)
--       - clinic_settings 'expense_shift_limit_total' → overall cap per posting
--         user per shift (0 = none)
--     Admin postings bypass both (they own the limits).
--   * EXP-YYYY-NNNN voucher series, same atomic-upsert counter pattern as
--     refunds. Voiding (admin only) keeps the row + number for the audit trail;
--     voided expenses drop out of all totals.
--
-- Idempotent: safe to paste into phpMyAdmin repeatedly.

CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) UNIQUE NOT NULL,
    -- Max total that may be posted under this category per shift (per calendar
    -- day, all users combined). 0 = no per-category cap.
    shift_limit DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS expense_sequences (
    sequence_year SMALLINT NOT NULL PRIMARY KEY,
    last_sequence INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_number VARCHAR(30) UNIQUE NOT NULL,
    category_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    -- Who the cash was handed to (vendor, rider, staff member...). Free text.
    paid_to VARCHAR(120) NULL,
    -- The shift this cash left the drawer on. Defaults to today at posting time;
    -- kept separate from created_at so a late back-entry can still land on the
    -- right tally date (admin-only correction).
    expense_date DATE NOT NULL,
    -- Where the money came from. Only the counter today; the enum leaves room
    -- for a petty-cash float or bank later without a schema change.
    source ENUM('CASH_COUNTER') NOT NULL DEFAULT 'CASH_COUNTER',
    posted_by_id INT NOT NULL,
    -- Void = admin-reversed. Row and voucher number are kept forever; totals
    -- and limit math exclude voided rows.
    voided_at TIMESTAMP NULL,
    voided_by_id INT NULL,
    void_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id),
    FOREIGN KEY (posted_by_id) REFERENCES users(id),
    FOREIGN KEY (voided_by_id) REFERENCES users(id),
    INDEX idx_expense_date (expense_date),
    INDEX idx_expense_cat_date (category_id, expense_date)
);

-- ---- Overall per-shift limit lives in clinic_settings (0 = no cap) ----
-- clinic_settings was dropped by sql/drop_unused.sql (2026-07-21) as unused at
-- the time; recreate it here — the expense module is its first real consumer.
CREATE TABLE IF NOT EXISTS clinic_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value VARCHAR(100) NOT NULL
);

INSERT INTO clinic_settings (setting_key, setting_value)
SELECT 'expense_shift_limit_total', '5000'
WHERE NOT EXISTS (
    SELECT 1 FROM clinic_settings WHERE setting_key = 'expense_shift_limit_total'
);

-- ---- Preloaded categories (admin can rename / cap / deactivate / add more) ----
INSERT INTO expense_categories (name, shift_limit)
SELECT * FROM (
    SELECT 'Utilities (Electricity / Gas / Water)' AS name, 0 AS shift_limit
    UNION ALL SELECT 'Medical Supplies', 0
    UNION ALL SELECT 'Stationery & Printing', 0
    UNION ALL SELECT 'Cleaning & Maintenance', 0
    UNION ALL SELECT 'Refreshments / Pantry', 0
    UNION ALL SELECT 'Transport & Fuel', 0
    UNION ALL SELECT 'Repairs & Equipment', 0
    UNION ALL SELECT 'Internet & Phone', 0
    UNION ALL SELECT 'Miscellaneous', 0
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM expense_categories ec WHERE ec.name = seed.name);

-- ---- Permission: who may post expenses from the counter ----
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_POST_EXPENSES' AS `key`,
           'Post expenses from the cash counter' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Receptionists post day-to-day; accountants and admin too. Revoke per-user via
-- the existing overrides UI if a particular receptionist shouldn't.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'ADMIN' AS base_role
    UNION ALL SELECT 'RECEPTIONIST'
    UNION ALL SELECT 'ACCOUNTANT'
    UNION ALL SELECT 'MANAGER'
) r
JOIN permissions p ON p.`key` = 'FINANCIAL_POST_EXPENSES'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
