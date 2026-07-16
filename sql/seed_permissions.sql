-- Permission catalog (HMIS-COMPLETE.md §1.2) + default role_permissions grants.
-- Idempotent: safe to re-run, uses INSERT ... WHERE NOT EXISTS per key.

-- ================= PERMISSION CATALOG =================

INSERT INTO permissions (`key`, label, category)
SELECT * FROM (SELECT
    'NURSING_RECORD_VITALS' AS `key`, 'Record vitals (height, weight, BP, temp, HR, O2, RR)' AS label, 'clinical' AS category
    UNION ALL SELECT 'NURSING_ATTEND_SHORT_STAY', 'Attend short-stay patient (bed management)', 'clinical'
    UNION ALL SELECT 'NURSING_LOG_CHARGEABLE_EVENTS', 'Log chargeable events (injections, drips, services)', 'clinical'
    UNION ALL SELECT 'NURSING_DISCHARGE_PATIENT', 'Discharge patient', 'clinical'
    UNION ALL SELECT 'NURSING_SKIP_ROTATION', 'Skip busy nurse in rotation (override assignment)', 'clinical'
    UNION ALL SELECT 'NURSING_SELF_ATTEND', 'Self-attend patient instead of allotting nurse', 'clinical'
    UNION ALL SELECT 'NURSING_PERFORM_PROCEDURES', 'Perform procedures (if trained/authorized)', 'clinical'
    UNION ALL SELECT 'NURSING_RECORD_ADMISSIONS', 'Record short-stay admissions', 'clinical'

    UNION ALL SELECT 'RECEPTION_REGISTER_PATIENTS', 'Register/search patients', 'admin'
    UNION ALL SELECT 'RECEPTION_GENERATE_OPD_SLIPS', 'Generate OPD slips', 'admin'
    UNION ALL SELECT 'RECEPTION_CAPTURE_PAYMENT_MODE', 'Capture payment mode (cash/digital)', 'admin'
    UNION ALL SELECT 'RECEPTION_PROCESS_PAYMENTS', 'Process payments (finalize bill)', 'financial'
    UNION ALL SELECT 'RECEPTION_PRINT_CONSENT', 'Print consent forms', 'admin'
    UNION ALL SELECT 'RECEPTION_UPLOAD_CONSENT', 'Upload signed consent forms', 'admin'
    UNION ALL SELECT 'RECEPTION_GENERATE_INVOICES', 'Generate invoices', 'financial'

    UNION ALL SELECT 'CLINICAL_VIEW_MEDICAL_RECORD', 'View patient medical record', 'clinical'
    UNION ALL SELECT 'CLINICAL_VIEW_CONSULTATION_NOTES', 'View consultation notes', 'clinical'
    UNION ALL SELECT 'CLINICAL_VIEW_VITALS_HISTORY', 'View vitals history', 'clinical'
    UNION ALL SELECT 'CLINICAL_VIEW_PAST_PROCEDURES', 'View past prescriptions/procedures', 'clinical'
    UNION ALL SELECT 'CLINICAL_ADD_NOTES', 'Add clinical notes', 'clinical'

    UNION ALL SELECT 'FINANCIAL_VIEW_OWN_EARNINGS', 'View own commission/earnings', 'financial'
    UNION ALL SELECT 'FINANCIAL_VIEW_ALL_COMMISSIONS', 'View all doctor/staff commissions', 'financial'
    UNION ALL SELECT 'FINANCIAL_VIEW_CLINIC_REPORTS', 'View clinic financial reports', 'financial'
    UNION ALL SELECT 'FINANCIAL_VIEW_DAILY_PL', 'View daily income/expense', 'financial'
    UNION ALL SELECT 'FINANCIAL_VIEW_INVOICES', 'View patient invoices', 'financial'

    UNION ALL SELECT 'ADMIN_MANAGE_USERS', 'Manage users/staff', 'admin'
    UNION ALL SELECT 'ADMIN_ASSIGN_PERMISSIONS', 'Assign permissions', 'admin'
    UNION ALL SELECT 'ADMIN_EDIT_STAFF_DETAILS', 'Edit staff details', 'admin'
    UNION ALL SELECT 'ADMIN_VIEW_AUDIT_LOGS', 'View audit logs', 'admin'
    UNION ALL SELECT 'ADMIN_MANAGE_PROCEDURE_MASTER', 'Manage procedure master', 'admin'
    UNION ALL SELECT 'ADMIN_MANAGE_CONSENT_TEMPLATES', 'Manage consent templates', 'admin'
    UNION ALL SELECT 'ADMIN_MANAGE_EXPENSE_CATEGORIES', 'Manage expense categories', 'admin'
    UNION ALL SELECT 'ADMIN_CONFIGURE_FINANCIAL_SETTINGS', 'Configure financial settings', 'admin'
    UNION ALL SELECT 'ADMIN_CONFIGURE_COMMISSION_SETTINGS', 'Configure staff commission settings', 'admin'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- ================= DEFAULT ROLE GRANTS =================
-- ADMIN gets everything.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id FROM permissions p
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'ADMIN' AND rp.permission_id = p.id
);

-- DOCTOR: clinical + own earnings.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'DOCTOR', p.id FROM permissions p
WHERE p.`key` IN (
    'CLINICAL_VIEW_MEDICAL_RECORD','CLINICAL_VIEW_CONSULTATION_NOTES','CLINICAL_VIEW_VITALS_HISTORY',
    'CLINICAL_VIEW_PAST_PROCEDURES','CLINICAL_ADD_NOTES',
    'NURSING_PERFORM_PROCEDURES','NURSING_RECORD_VITALS',
    'FINANCIAL_VIEW_OWN_EARNINGS'
)
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'DOCTOR' AND rp.permission_id = p.id);

-- MANAGER: reports + QA oversight, no clinical edit.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'MANAGER', p.id FROM permissions p
WHERE p.`key` IN (
    'FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_CLINIC_REPORTS','FINANCIAL_VIEW_DAILY_PL','FINANCIAL_VIEW_INVOICES',
    'ADMIN_VIEW_AUDIT_LOGS','ADMIN_MANAGE_EXPENSE_CATEGORIES'
)
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'MANAGER' AND rp.permission_id = p.id);

-- ACCOUNTANT: financial read/write, no clinical.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'ACCOUNTANT', p.id FROM permissions p
WHERE p.`key` IN (
    'FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_CLINIC_REPORTS','FINANCIAL_VIEW_DAILY_PL','FINANCIAL_VIEW_INVOICES',
    'RECEPTION_PROCESS_PAYMENTS','RECEPTION_GENERATE_INVOICES',
    'ADMIN_CONFIGURE_FINANCIAL_SETTINGS','ADMIN_MANAGE_EXPENSE_CATEGORIES'
)
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'ACCOUNTANT' AND rp.permission_id = p.id);

-- NURSE: all nursing + own earnings + clinical view.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'NURSE', p.id FROM permissions p
WHERE (p.category = 'clinical' OR p.`key` = 'FINANCIAL_VIEW_OWN_EARNINGS')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'NURSE' AND rp.permission_id = p.id);

-- RECEPTIONIST: all reception permissions.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'RECEPTIONIST', p.id FROM permissions p
WHERE p.`key` IN (
    'RECEPTION_REGISTER_PATIENTS','RECEPTION_GENERATE_OPD_SLIPS','RECEPTION_CAPTURE_PAYMENT_MODE',
    'RECEPTION_PROCESS_PAYMENTS','RECEPTION_PRINT_CONSENT','RECEPTION_UPLOAD_CONSENT','RECEPTION_GENERATE_INVOICES'
)
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = 'RECEPTIONIST' AND rp.permission_id = p.id);
