-- Lab catalogue addition (2026-11-12): Blood Counts for Dengue, from Premium Lab report 2611-02425
-- (adult patient, 54 yr F; adult female ranges, one range per test for now). Idempotent.
-- Run AFTER seed_lab_tests.sql.
INSERT IGNORE INTO lab_tests (section, name, sort_order) VALUES
    ('Hematology', 'Blood Counts for Dengue', 15);

INSERT IGNORE INTO lab_test_params (test_id, group_label, name, unit, ref_range, sort_order)
SELECT t.id, v.g, v.n, v.u, v.r, v.s FROM lab_tests t JOIN (
    SELECT '' g, 'WBC Count' n, '/cmm' u, '4000-11000' r, 10 s
UNION ALL
    SELECT '' g, 'Hemoglobin Level' n, 'g/dL' u, '12.0-16.0' r, 20 s
UNION ALL
    SELECT '' g, 'Hematocrit' n, '%' u, '40-50' r, 30 s
UNION ALL
    SELECT '' g, 'Platelets' n, '/cmm' u, '140,000-450,000' r, 40 s
) v WHERE t.name = 'Blood Counts for Dengue';
