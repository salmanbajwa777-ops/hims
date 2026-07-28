-- Why is a doctor missing from the procedure_bill.php performing-doctor picker?
-- The picker only lists doctors that pass ALL THREE gates in procedure_bill.php:358.
-- Run each block and read the verdict column.

-- 1. Does the doctor exist, and is he an ACTIVE user with base_role DOCTOR?
--    If is_active = 0 or base_role <> 'DOCTOR', he can never appear.
SELECT id, name, base_role, is_active,
       CASE
         WHEN base_role <> 'DOCTOR' THEN 'FAIL - not a DOCTOR base_role'
         WHEN is_active = 0         THEN 'FAIL - user is deactivated'
         ELSE 'PASS - user gate ok'
       END AS verdict
FROM users
WHERE name LIKE '%BASHEER%';

-- 2. What procedures are assigned to him, and are both ends active?
--    Needs >= 1 row where dp.is_active = 1 AND pm.is_active = 1.
SELECT u.id AS doctor_id, u.name AS doctor,
       pm.name AS procedure_name,
       dp.is_active AS assignment_active,
       pm.is_active AS master_active,
       COALESCE(dp.fee, pm.fee) AS effective_fee,
       CASE
         WHEN dp.is_active = 0 THEN 'FAIL - assignment switched off'
         WHEN pm.is_active = 0 THEN 'FAIL - master procedure switched off'
         ELSE 'PASS - this row makes him selectable'
       END AS verdict
FROM users u
LEFT JOIN doctor_procedures dp ON dp.doctor_id = u.id
LEFT JOIN procedure_master  pm ON pm.id = dp.procedure_master_id
WHERE u.name LIKE '%BASHEER%';
-- Zero rows here = no assignments at all: that is the whole reason he is missing.

-- 3. Does a Circumcision procedure exist in the master list, and is it active?
SELECT id, name, fee, is_active,
       CASE WHEN is_active = 1 THEN 'PASS - assignable'
            ELSE 'FAIL - reactivate it in procedure_master.php' END AS verdict
FROM procedure_master
WHERE name LIKE '%CIRCUM%';

-- 4. For reference: exactly who the picker WILL show right now.
--    This is the query from procedure_bill.php:358, verbatim.
SELECT DISTINCT u.id, u.name
FROM users u
JOIN doctor_procedures dp ON dp.doctor_id = u.id AND dp.is_active = 1
JOIN procedure_master pm ON pm.id = dp.procedure_master_id AND pm.is_active = 1
WHERE u.base_role = 'DOCTOR' AND u.is_active = 1
ORDER BY u.name;
