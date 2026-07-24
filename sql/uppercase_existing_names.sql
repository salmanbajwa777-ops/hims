-- One-time backfill: convert all existing person names to ALL CAPS.
-- Going forward the app stores names uppercased on save; this brings older
-- rows into line so lists, invoices, slips and vouchers all read uniformly.
--
-- Run once in phpMyAdmin (SQL tab). Safe to re-run — UPPER() is idempotent.
-- UTF-8: MySQL's UPPER() is charset-aware on utf8mb4 columns.

UPDATE patients   SET name        = UPPER(name)        WHERE name        IS NOT NULL AND name        <> UPPER(name);
UPDATE patients   SET father_name = UPPER(father_name) WHERE father_name IS NOT NULL AND father_name <> UPPER(father_name);

UPDATE users      SET name        = UPPER(name)        WHERE name        IS NOT NULL AND name        <> UPPER(name);

UPDATE bookings   SET person_name = UPPER(person_name) WHERE person_name IS NOT NULL AND person_name <> UPPER(person_name);

UPDATE admissions SET admitting_doctor_manual = UPPER(admitting_doctor_manual)
    WHERE admitting_doctor_manual IS NOT NULL AND admitting_doctor_manual <> UPPER(admitting_doctor_manual);
