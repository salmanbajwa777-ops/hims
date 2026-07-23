-- Per-receptionist shift closings + post-close edits (approved 2026-07-23).
--
-- Model change from add_shift_closings.sql:
--   * One closing per (cashier, date) — each receptionist closes THEIR OWN
--     shift; a colleague closing does not lock anyone else out. The day-lock
--     becomes per-user (see require_day_open() in config/billing.php).
--   * NO FLOAT in personal tallies (confirmed): expected cash = the user's
--     cash payments − their cash refunds − their expenses. The physical
--     drawer float is admin's concern, outside individual accountability.
--   * Attribution: bills/admission_bills gain paid_by_id — WHO RECORDED THE
--     PAYMENT (checkout / discharge screen), stamped at record_payment time.
--     bills.created_by_id (who raised the bill) is not the collector.
--     Historical rows: paid_by_id backfilled from created_by_id /
--     finalized_by_id as the best available approximation.
--   * Edits: the cashier may edit their own closing any number of times while
--     status='PENDING_RECEIPT'. Each edit applies IMMEDIATELY, flips status
--     to 'EDITED', logs old→new per field in shift_closing_edits, and emails
--     admin. Admin's "mark received" approves the changes and locks forever.
--
-- Idempotent: guarded ALTERs via a stored procedure + IF NOT EXISTS tables.
-- Depends on: add_shift_closings.sql AND add_closing_expenses.sql (both run).

DROP PROCEDURE IF EXISTS per_user_closings_migrate;
DELIMITER $$
CREATE PROCEDURE per_user_closings_migrate()
BEGIN
    -- 1. Collector stamp on consultation bills.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'bills' AND column_name = 'paid_by_id'
    ) THEN
        ALTER TABLE bills
            ADD COLUMN paid_by_id INT NULL AFTER paid_at,
            ADD CONSTRAINT bills_paid_by_fk FOREIGN KEY (paid_by_id) REFERENCES users(id);
        -- Best-effort backfill: whoever raised the bill probably took the cash.
        UPDATE bills SET paid_by_id = created_by_id WHERE status = 'paid' AND paid_by_id IS NULL;
    END IF;

    -- 2. Collector stamp on admission bills.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'paid_by_id'
    ) THEN
        ALTER TABLE admission_bills
            ADD COLUMN paid_by_id INT NULL AFTER paid_at,
            ADD CONSTRAINT admission_bills_paid_by_fk FOREIGN KEY (paid_by_id) REFERENCES users(id);
        UPDATE admission_bills SET paid_by_id = finalized_by_id WHERE status = 'paid' AND paid_by_id IS NULL;
    END IF;

    -- 3. One closing per (cashier, date) instead of per date.
    IF EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'shift_closings'
          AND index_name = 'closing_date' AND non_unique = 0
    ) THEN
        ALTER TABLE shift_closings
            DROP INDEX closing_date,
            ADD UNIQUE KEY uq_cashier_date (cashier_id, closing_date);
    END IF;

    -- 4. EDITED status + last-edit stamp.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'edited_at'
    ) THEN
        ALTER TABLE shift_closings
            MODIFY status ENUM('PENDING_RECEIPT','EDITED','RECEIVED') NOT NULL DEFAULT 'PENDING_RECEIPT',
            ADD COLUMN edited_at TIMESTAMP NULL AFTER printed_at,
            ADD COLUMN edit_count INT NOT NULL DEFAULT 0 AFTER edited_at;
    END IF;
END$$
DELIMITER ;
CALL per_user_closings_migrate();
DROP PROCEDURE per_user_closings_migrate;

-- 5. Change log: one row per field changed per edit, so admin sees exactly
--    what moved (old → new), when, and in which edit round.
CREATE TABLE IF NOT EXISTS shift_closing_edits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    closing_id INT NOT NULL,
    edit_round INT NOT NULL,                    -- 1st edit, 2nd edit...
    field_name VARCHAR(40) NOT NULL,            -- counted_cash / handover_declared / variance_note / denominations
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,
    edited_by_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (closing_id) REFERENCES shift_closings(id) ON DELETE CASCADE,
    FOREIGN KEY (edited_by_id) REFERENCES users(id),
    INDEX idx_sce_closing (closing_id)
);
