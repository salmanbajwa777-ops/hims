-- =============================================================================
-- OPD consultation notes (2026-07-29).
--
-- The doctor's own clinical note for ONE outpatient visit. Until now the doctor
-- console had a permanently-disabled "Note" button and the right rail said
-- "Consultation notes & prescriptions land in the next phase" — there was no
-- table behind it at all. This is that table.
--
-- PRIVACY — this is the whole point of the module:
--   A note is readable ONLY by the doctor who wrote it. Not by reception, not
--   by other doctors, not by the admin/owner in the UI. Every read path in the
--   app MUST filter `doctor_id = <the logged-in doctor>`; there is no
--   permission key that opens someone else's notes, deliberately, so a future
--   grant can't accidentally widen this.
--
--   This is UI-level confidentiality, not encryption. Anyone with direct
--   database access (phpMyAdmin, a backup file) can still read the rows. That
--   is an accepted limit — recorded here so nobody later mistakes this for a
--   sealed record.
--
-- MUTABILITY — deliberately different from IPD daily-round notes:
--   ipd_round_notes are frozen once written (immutable clinical record). These
--   OPD notes stay editable by their author for as long as the note exists,
--   which is the behaviour asked for. updated_at moves on every edit and each
--   save is written to audit_log, so there is still a trail of when a note was
--   revised even though prior text is not retained.
--
-- ONE note per visit: the doctor writes a single note for the consultation and
-- revises it in place, so (visit_id) is UNIQUE and saving is an upsert rather
-- than an ever-growing list of fragments.
--
-- doctor_id is stored on the row rather than joined through visits.doctor_id.
-- It is the authorship record — who actually typed this — and the column every
-- read filters on. Reading ownership through a join would mean a later change
-- to a visit's assigned doctor silently hands that visit's private note to a
-- different person.
--
-- No stored procedures — the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- No ENGINE/CHARSET clause: it must match the tables it references (visits,
-- users), and forcing utf8mb4 has silently aborted a migration here before
-- (errno 150).
-- Idempotent: safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS consultation_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- The visit this note describes. UNIQUE: one note per consultation, revised
    -- in place. CASCADE so deleting a visit takes its private note with it
    -- rather than orphaning clinical text.
    visit_id INT NOT NULL UNIQUE,

    -- The author, and the ONLY user who may read this row. Never resolve the
    -- reader through visits.doctor_id — see the header note.
    doctor_id INT NOT NULL,

    -- Denormalised so a doctor's note history can be listed for a patient
    -- without joining visits on every read, and so the note survives as a
    -- patient-scoped record. Set from visits.patient_id at write time.
    patient_id INT NOT NULL,

    -- The note itself. TEXT, not VARCHAR: a consultation note is free-form and
    -- can legitimately run long (history, examination, advice) and truncating
    -- clinical text at a column limit is not an acceptable failure mode.
    note TEXT NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Moves on every revision. Compared against created_at in the UI to show
    -- an "edited" marker.
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (patient_id) REFERENCES patients(id),

    -- The patient-history read: "this doctor's notes for this patient, newest
    -- first". doctor_id leads because it is on EVERY read (the privacy filter),
    -- so the index is usable even when the patient is not yet pinned down.
    INDEX idx_note_doctor_patient (doctor_id, patient_id, created_at)
);
