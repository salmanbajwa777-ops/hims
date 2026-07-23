-- Google Sheet invoice log (one sheet per year, matching the pre-HMIS system).
--
-- Two parts:
--   1. patients.email — the old sheet logged a patient email; HMIS never collected
--      one. Optional field on registration, stored per PATIENT (not per visit) so a
--      returning patient's address auto-fills and every later row carries it.
--   2. sheet_sync_log — one row per push attempt. The push itself is fire-and-forget
--      (it must never block a payment), so this table is what makes a failed push
--      recoverable: cron/sheet_retry.php re-sends anything left 'failed'.
--
-- Idempotent: safe to re-run. MySQL on shared hosting has no
-- ADD COLUMN IF NOT EXISTS, so the column add probes information_schema first.

-- -----------------------------------------------------------------------------
-- 1. patients.email
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'email'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE patients ADD COLUMN email VARCHAR(190) NULL AFTER phone',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. sheet_sync_log
--
-- doc_type + doc_ref together identify what was pushed:
--   INVOICE   + bills.id            (consultation)
--   ADMISSION + admissions.id       (pushed at admit, amounts blank)
--   DISCHARGE + admission_bills.id  (pushed when the discharge bill is paid)
--
-- payload holds the exact JSON row that was sent, so a retry re-sends the figures
-- as they stood at the time rather than re-deriving them from a since-edited bill.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sheet_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_type ENUM('INVOICE','ADMISSION','DISCHARGE') NOT NULL,
    doc_ref INT NOT NULL,
    invoice_number VARCHAR(50) NULL,
    sheet_year INT NOT NULL,                  -- which yearly tab this belongs in
    payload MEDIUMTEXT NOT NULL,              -- the JSON row as sent
    status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'failed',
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    -- One log row per document. A retry UPDATEs this row rather than inserting a
    -- second one, which is also what stops a double push appending a duplicate
    -- row to the sheet.
    UNIQUE KEY uniq_doc (doc_type, doc_ref),
    INDEX idx_sheet_status (status, id)
);
