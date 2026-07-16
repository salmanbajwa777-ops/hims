-- Lets admin hard-delete a staff/doctor or a patient (for cleaning up test data) without
-- hitting FK errors, while not silently destroying OTHER people's history in the process.
--
-- Deleting a PATIENT removes their visits with them (the visit has no meaning without the
-- patient). Deleting a STAFF/DOCTOR does NOT delete visits they're attached to — that would
-- erase other patients' medical/financial history — instead the user-reference columns on
-- those visits are set NULL, same pattern already used for audit_logs.user_id.

ALTER TABLE visits
    DROP FOREIGN KEY visits_ibfk_1,
    DROP FOREIGN KEY visits_ibfk_2,
    DROP FOREIGN KEY visits_ibfk_3,
    DROP FOREIGN KEY visits_ibfk_4,
    DROP FOREIGN KEY visits_ibfk_5;

ALTER TABLE visits
    MODIFY doctor_id INT NULL,
    MODIFY discount_applied_by_id INT NULL,
    MODIFY created_by_id INT NULL,
    ADD CONSTRAINT visits_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    ADD CONSTRAINT visits_doctor_fk FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT visits_consult_type_fk FOREIGN KEY (doctor_consult_type_id) REFERENCES doctor_consult_types(id),
    ADD CONSTRAINT visits_discount_by_fk FOREIGN KEY (discount_applied_by_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT visits_created_by_fk FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE patients
    DROP FOREIGN KEY patients_ibfk_1;

ALTER TABLE patients
    MODIFY created_by_id INT NULL,
    ADD CONSTRAINT patients_created_by_fk FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE visit_queue_counters
    DROP FOREIGN KEY visit_queue_counters_ibfk_1;

ALTER TABLE visit_queue_counters
    ADD CONSTRAINT visit_queue_counters_doctor_fk FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE tasks
    DROP FOREIGN KEY tasks_ibfk_1,
    DROP FOREIGN KEY tasks_ibfk_2;

ALTER TABLE tasks
    MODIFY assigned_to_id INT NULL,
    MODIFY created_by_id INT NULL,
    ADD CONSTRAINT tasks_assigned_to_fk FOREIGN KEY (assigned_to_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT tasks_created_by_fk FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL;
