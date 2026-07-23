-- Bookings — phone-call appointments taken by reception.
--
-- Design (approved 2026-07-23):
--   * Day-level only: doctor + date (+ free-text preferred time). No slot grid —
--     the clinic runs on queue tokens and morning-confirmed doctor_day_timings.
--   * A booking is NOT a patient. patient_id links an existing patient when the
--     caller confirms one; new callers are just phone + name-as-spoken. The
--     patient record (MRN, invoice, revisit engine, discounts) is created at the
--     desk on arrival, exactly as today.
--   * No fee is quoted or stored — purpose is the doctor's own consult type
--     (doctor_consult_types), fee resolution stays an arrival-time concern.
--   * Consumption: registering/revisiting against the booking sets visit_id and
--     flips status to ARRIVED inside the SAME transaction as the visit insert.
--     visits.booking_id is the back-reference.
--   * NO_SHOW is swept by cron/mark_no_show.php after 22:00 PKT (plus a manual
--     button); CANCELLED is manual with a reason and emails the doctor.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + guarded ALTER — safe to paste into
-- phpMyAdmin. Run this BEFORE deploying the bookings code.

CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- E.164, same normalization as patients.phone (e.g. +923001234567).
    phone VARCHAR(20) NOT NULL,
    -- Existing patient the caller confirmed, if any. SET NULL on patient delete
    -- so the booking survives as a plain phone booking.
    patient_id INT NULL,
    -- Name as spoken on the phone — which child, or the new caller's name.
    person_name VARCHAR(100) NOT NULL,
    doctor_id INT NOT NULL,
    -- The "purpose": consultation vs procedure, from the doctor's own type list.
    doctor_consult_type_id INT NOT NULL,
    booking_date DATE NOT NULL,
    -- Free text ("after 5pm") — deliberately not a TIME column.
    preferred_time VARCHAR(40) NULL,
    note VARCHAR(255) NULL,
    status ENUM('BOOKED','ARRIVED','CANCELLED','NO_SHOW') NOT NULL DEFAULT 'BOOKED',
    -- Set when the booking is consumed by a visit (mirrored by visits.booking_id).
    visit_id INT NULL,
    created_by_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_by_id INT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    KEY idx_booking_date_status (booking_date, status),
    KEY idx_booking_phone (phone),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_consult_type_id) REFERENCES doctor_consult_types(id) ON DELETE CASCADE,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (cancelled_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- visits.booking_id back-reference. MySQL has no ADD COLUMN IF NOT EXISTS on
-- older versions, so guard via information_schema + dynamic SQL (same trick as
-- earlier HMIS migrations) — re-running this file is a no-op.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visits' AND COLUMN_NAME = 'booking_id'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE visits ADD COLUMN booking_id INT NULL, ADD CONSTRAINT fk_visits_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
