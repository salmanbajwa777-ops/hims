-- Short-stay bill review flags + draft-bill-at-admit marker.
-- RUN ONCE in phpMyAdmin (flat statements only - no stored procedures; CREATE ROUTINE
-- is denied on the host). Run order: 2 of 2 (independent of 1, either order is safe).
-- The CREATE TABLE is re-runnable; the ALTER and the backfill are one-shot - a second
-- run of the ALTER fails with "Duplicate column name", which is harmless.
-- Code is guarded by information_schema: before this runs, admit raises no bill header
-- (the discharge page creates the bill lazily as before) and the finalise screen shows
-- no "left before treatment" outcome and raises no approval rows.
-- No permission seed: approvers are anyone holding ADMISSION_APPROVE_WRITEOFF
-- (ADMIN + MANAGER already), and the sidebar entry uses that same key.

CREATE TABLE IF NOT EXISTS admission_bill_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    bill_id INT NULL,
    kind ENUM('LEFT_BEFORE_TREATMENT','SHORT_PAYMENT','ZERO_OR_DISCOUNT') NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    requested_by_id INT NOT NULL,
    requested_at DATETIME NOT NULL,
    status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    decided_by_id INT NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    INDEX idx_aba_status (status, id),
    INDEX idx_aba_adm (admission_id, kind, status),
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (bill_id) REFERENCES admission_bills(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by_id) REFERENCES users(id),
    FOREIGN KEY (decided_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- NULL = "lines not seeded yet". Admit now raises the bill header with no lines; the
-- discharge page seeds stay + services once, from the nurse's discharged_at.
ALTER TABLE admission_bills ADD COLUMN items_seeded_at DATETIME NULL;

-- Every bill that already exists has its lines: mark them seeded so none is re-seeded.
UPDATE admission_bills SET items_seeded_at = created_at WHERE items_seeded_at IS NULL;
