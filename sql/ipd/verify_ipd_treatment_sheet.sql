-- =============================================================================
-- VERIFY: add_ipd_treatment_sheet.sql actually ran.
--
-- Run this in phpMyAdmin AFTER the migration. Every row must read PASS.
-- A green "query OK" on the migration is NOT evidence — this is.
--
-- Note the ORDER BY on a UNION: MySQL applies it to the whole result set, so
-- the sort column is carried explicitly rather than relying on branch order.
-- App tables are fully qualified after the information_schema branches, because
-- an unqualified name across a UNION with information_schema can raise #1109.
-- =============================================================================

SELECT chk, status, detail FROM (

-- ---- 1. Tables exist ----
SELECT 1 AS ord, 'tables_created' AS chk,
       CASE WHEN COUNT(*) = 6 THEN 'PASS' ELSE 'FAIL' END AS status,
       CONCAT(COUNT(*), ' of 6 tables present') AS detail
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('ipd_drug_formulary','patient_allergies','ipd_medication_orders',
                     'ipd_medication_admins','ipd_medication_audit','ipd_frequency_times')

UNION ALL

-- ---- 2. The approval gate columns exist on the order table ----
SELECT 2, 'order_approval_columns',
       CASE WHEN COUNT(*) = 4 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' of 4 approval columns present')
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_orders'
  AND column_name IN ('approval_status','approved_by_id','approved_at','rejected_reason')

UNION ALL

-- ---- 3. Discontinuation columns (never-delete rule) ----
SELECT 3, 'order_discontinue_columns',
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' of 3 discontinue columns present')
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_orders'
  AND column_name IN ('discontinued_by_id','discontinued_at','discontinued_reason')

UNION ALL

-- ---- 4. Route enum carries all 10 codes ----
SELECT 4, 'route_enum',
       CASE WHEN column_type LIKE '%PO%' AND column_type LIKE '%IV%' AND column_type LIKE '%IM%'
                 AND column_type LIKE '%SC%' AND column_type LIKE '%PR%' AND column_type LIKE '%PV%'
                 AND column_type LIKE '%TOP%' AND column_type LIKE '%NEB%' AND column_type LIKE '%SL%'
                 AND column_type LIKE '%NG%'
            THEN 'PASS' ELSE 'FAIL' END,
       column_type
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_orders' AND column_name = 'route'

UNION ALL

-- ---- 5. Frequency enum carries all 8 codes ----
SELECT 5, 'frequency_enum',
       CASE WHEN column_type LIKE '%OD%' AND column_type LIKE '%BD%' AND column_type LIKE '%TDS%'
                 AND column_type LIKE '%QID%' AND column_type LIKE '%Q6H%' AND column_type LIKE '%Q8H%'
                 AND column_type LIKE '%STAT%' AND column_type LIKE '%PRN%'
            THEN 'PASS' ELSE 'FAIL' END,
       column_type
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_orders' AND column_name = 'frequency'

UNION ALL

-- ---- 6. Slot status enum includes CANCELLED (discontinuation target) ----
SELECT 6, 'slot_status_enum',
       CASE WHEN column_type LIKE '%CANCELLED%' AND column_type LIKE '%GIVEN%'
                 AND column_type LIKE '%HELD%' AND column_type LIKE '%MISSED%'
            THEN 'PASS' ELSE 'FAIL' END,
       column_type
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_admins' AND column_name = 'status'

UNION ALL

-- ---- 7. The double-tap guard on administration slots ----
SELECT 7, 'slot_unique_key',
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT('uq_slot covers ', COUNT(*), ' of 3 columns')
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_admins' AND index_name = 'uq_slot'

UNION ALL

-- ---- 8. Frequency times seeded ----
SELECT 8, 'frequency_times_seeded',
       CASE WHEN COUNT(*) >= 8 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' frequency codes seeded (expect 8)')
FROM ipd_frequency_times

UNION ALL

-- ---- 9. TDS really maps to three clock times (the slot generator depends on it) ----
SELECT 9, 'tds_mapping',
       CASE WHEN times_csv = '08:00,14:00,20:00' AND slots_per_day = 3 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT('TDS -> ', times_csv, ' (', slots_per_day, '/day)')
FROM ipd_frequency_times WHERE code = 'TDS'

UNION ALL

-- ---- 10. Starter formulary seeded ----
SELECT 10, 'formulary_seeded',
       CASE WHEN COUNT(*) >= 45 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' drugs in formulary (expect >= 45)')
FROM ipd_drug_formulary

UNION ALL

-- ---- 10b. Generic and brand are SEPARATE columns on both tables ----
SELECT 100, 'generic_brand_split',
       CASE WHEN COUNT(*) = 5 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' of 5 generic/brand columns present')
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ( (table_name = 'ipd_drug_formulary'    AND column_name IN ('generic_name','brand_name','brand_names'))
     OR (table_name = 'ipd_medication_orders' AND column_name IN ('generic_name_snapshot','brand_name_snapshot')) )

UNION ALL

-- ---- 10c. Brands actually seeded into their own column ----
SELECT 101, 'brand_name_seeded',
       CASE WHEN COUNT(*) >= 40 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' drugs carry a primary brand_name (expect >= 40)')
FROM ipd_drug_formulary WHERE brand_name IS NOT NULL AND brand_name <> ''

UNION ALL

-- ---- 11. High-alert drugs flagged ----
SELECT 11, 'high_alert_flagged',
       CASE WHEN COUNT(*) >= 12 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' high-alert drugs flagged (expect >= 12)')
FROM ipd_drug_formulary WHERE is_high_alert = 1

UNION ALL

-- ---- 12. All 7 permissions exist ----
SELECT 12, 'permissions_created',
       CASE WHEN COUNT(*) = 7 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' of 7 permissions present')
FROM permissions
WHERE `key` IN ('IPD_VIEW_TREATMENT_SHEET','IPD_WRITE_MED_ORDER','IPD_APPROVE_MED_ORDER',
                'IPD_ADMINISTER_MED','IPD_DISCONTINUE_MED','IPD_MANAGE_FORMULARY','IPD_MANAGE_ALLERGIES')

UNION ALL

-- ---- 13. THE DOCTOR GATE: staff must NOT hold approve or discontinue ----
--       This is the one check that encodes the clinical safety rule. If it ever
--       reads FAIL, staff can self-approve their own medication orders.
SELECT 13, 'doctor_gate_intact',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' STAFF grants on approve/discontinue (MUST be 0)')
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.base_role = 'STAFF'
  AND p.`key` IN ('IPD_APPROVE_MED_ORDER','IPD_DISCONTINUE_MED')

UNION ALL

-- ---- 14. Doctors CAN approve ----
SELECT 14, 'doctor_can_approve',
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' of 2 approve/discontinue grants on DOCTOR')
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.base_role = 'DOCTOR'
  AND p.`key` IN ('IPD_APPROVE_MED_ORDER','IPD_DISCONTINUE_MED')

UNION ALL

-- ---- 15. Staff CAN write an order and give a dose ----
SELECT 15, 'staff_can_write_and_give',
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' of 2 write/administer grants on STAFF')
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.base_role = 'STAFF'
  AND p.`key` IN ('IPD_WRITE_MED_ORDER','IPD_ADMINISTER_MED')

UNION ALL

-- ---- 16. Formulary management is admin-only ----
SELECT 16, 'formulary_admin_only',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' non-admin grants on IPD_MANAGE_FORMULARY (MUST be 0)')
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.`key` = 'IPD_MANAGE_FORMULARY' AND rp.base_role <> 'ADMIN'

UNION ALL

-- ---- 17. FK from orders back to the IPD admission (scope: IPD only) ----
SELECT 17, 'order_fk_admission',
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' FK ipd_medication_orders.admission_id -> ipd_admissions')
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_orders'
  AND column_name = 'admission_id' AND referenced_table_name = 'ipd_admissions'

UNION ALL

-- ---- 18. Allergies hang off the PATIENT, not the admission ----
SELECT 18, 'allergy_fk_patient',
       CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' FK patient_allergies.patient_id -> patients')
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = 'patient_allergies'
  AND column_name = 'patient_id' AND referenced_table_name = 'patients'

) AS checks
ORDER BY ord;
