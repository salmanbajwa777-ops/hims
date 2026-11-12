-- Lab catalogue addition (2026-11-12): Serum Electrolytes, from Premium Lab report 2611-02456
-- (paediatric patient, 3 yr F; one range per test for now). Idempotent. Run AFTER seed_lab_tests.sql.
INSERT IGNORE INTO lab_tests (section, name, sort_order) VALUES
    ('Clinical Chemistry', 'Serum Electrolytes', 25);

INSERT IGNORE INTO lab_test_params (test_id, group_label, name, unit, ref_range, sort_order)
SELECT t.id, v.g, v.n, v.u, v.r, v.s FROM lab_tests t JOIN (
    SELECT '' g, 'Sodium' n, 'mmol/L' u, '135-145' r, 10 s
UNION ALL
    SELECT '' g, 'Potassium' n, 'mmol/L' u, '3.4 - 4.7' r, 20 s
UNION ALL
    SELECT '' g, 'Chloride' n, 'mmol/L' u, '98-107' r, 30 s
) v WHERE t.name = 'Serum Electrolytes';
