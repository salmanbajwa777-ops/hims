-- =============================================================================
-- Employee fines — post a disciplinary/attendance fine against a staff member,
-- review them with a monthly filter, and bulk-clear a person's outstanding
-- fines from staff_fines.php.
--
-- Modeled after VacEmp's `fines` table (same shape: employee_id, amount,
-- reason, fine_date), but HIMS has no payroll ledger to auto-deduct against
-- (see staff.php / the Salaries expense category — it's a manual, un-itemized
-- expense bucket, not a per-employee ledger). So "clear" here is an explicit
-- admin action with two modes, not an automatic payroll fold-in:
--   FORGIVEN  — waived, no money moves anywhere.
--   DEDUCTED  — admin is recording that this WAS taken out of the employee's
--               salary (manually, outside the system); this table just marks
--               it settled so it stops showing as outstanding.
-- Both are terminal states reached only through the bulk-clear action on
-- staff_fines.php, by an ADMIN. A fine that was never cleared stays PENDING
-- indefinitely — there is no auto-expiry.
--
-- Idempotent: safe to run more than once.
-- =============================================================================

CREATE TABLE IF NOT EXISTS employee_fines (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    reason        VARCHAR(255) NOT NULL,
    fine_date     DATE NOT NULL,
    status        ENUM('PENDING','FORGIVEN','DEDUCTED') NOT NULL DEFAULT 'PENDING',
    cleared_at    DATETIME DEFAULT NULL,
    cleared_by_id INT DEFAULT NULL,
    posted_by_id  INT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cleared_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (posted_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_employee_fines_emp (employee_id),
    INDEX idx_employee_fines_status (status),
    INDEX idx_employee_fines_date (fine_date)
);

-- Permission catalog entry. Sits in the 'managerial' category alongside the
-- rest of the Manager oversight bundle (sql/add_manager_role.sql).
INSERT INTO permissions (`key`, label, category)
SELECT 'MANAGERIAL_POST_FINE', 'Post & Clear Staff Fines', 'managerial'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE `key` = 'MANAGERIAL_POST_FINE');

-- Grant to MANAGER and ADMIN by default. ADMIN doesn't strictly need the row
-- (guard_admin.php-gated actions bypass has_permission() entirely) but every
-- other managerial key is explicitly granted to ADMIN too, so the Permissions
-- screen shows it checked for both roles rather than looking unset for one.
-- Guarded with a live-ENUM probe so this never fails on a DB where
-- add_manager_role.sql hasn't run yet — same pattern staff.php/permissions.php
-- use to detect whether 'MANAGER' is actually a valid base_role value.
INSERT INTO role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id FROM permissions p
WHERE p.`key` = 'MANAGERIAL_POST_FINE'
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role='ADMIN' AND rp.permission_id=p.id);

INSERT INTO role_permissions (base_role, permission_id)
SELECT 'MANAGER', p.id FROM permissions p
WHERE p.`key` = 'MANAGERIAL_POST_FINE'
AND EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'role_permissions'
       AND column_name = 'base_role' AND column_type LIKE '%MANAGER%'
)
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role='MANAGER' AND rp.permission_id=p.id);

-- Verify (read-only)
SELECT
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'employee_fines') AS table_created,
    (SELECT COUNT(*) FROM permissions WHERE `key` = 'MANAGERIAL_POST_FINE') AS permission_seeded,
    (SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
      WHERE p.`key` = 'MANAGERIAL_POST_FINE') AS role_grants;
