-- =============================================================================
-- Phase 4 — mandatory shift-close hand-off for open short-stay admissions
--
-- RUN ONCE in phpMyAdmin against the hims database. Flat CREATE only (no stored
-- procedure). Safe to re-run (IF NOT EXISTS). Code is written to tolerate this
-- table being ABSENT: until it exists the close-block is OFF and nothing fatals.
--
-- NAME NOTE: the brief called this table `admission_handovers`, but that name is
-- ALREADY TAKEN by the nurse-to-nurse shift handover table (sql/add_admissions.sql,
-- used by admission.php / doctor.php). Re-using it would silently no-op the
-- CREATE and then break every INSERT. This table is therefore
-- `admission_reception_handovers` (receptionist-to-receptionist).
--
-- A row = one receptionist passing one open admission to the next receptionist.
--   status PASSED   : in transit. to_user_id NULL = "any receptionist", else a
--                     chosen receiver. Nobody is on the hook at the close page
--                     while it is in transit (the sender is released, the
--                     receiver has not yet taken it) - it stays visible to the
--                     manager via pending_handovers_unaccepted().
--   status ACCEPTED : receiver clicked Accept; accepted_by_id now OWNS the
--                     admission and it counts in THEIR close obligation.
-- The latest row per admission decides ownership (see config/handover_helpers.php).
-- =============================================================================

CREATE TABLE IF NOT EXISTS admission_reception_handovers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    from_user_id INT NOT NULL,
    to_user_id INT NULL,
    note VARCHAR(500) NOT NULL,
    passed_at DATETIME NOT NULL,
    accepted_by_id INT NULL,
    accepted_at DATETIME NULL,
    status ENUM('PASSED','ACCEPTED') NOT NULL DEFAULT 'PASSED',
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id),
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (accepted_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_arh_admission (admission_id, id),
    INDEX idx_arh_status (status, passed_at),
    INDEX idx_arh_to (to_user_id, status)
);

-- Verify:
-- SELECT table_name FROM information_schema.tables
--  WHERE table_schema = DATABASE() AND table_name = 'admission_reception_handovers';
