-- Per-doctor consultation types + fees, managed by admin on staff.php's doctor edit panel.
-- Registration reads whichever types are configured for the selected doctor — there is no
-- separate global rate_master driving this; the doctor's own profile is the source.

CREATE TABLE IF NOT EXISTS doctor_consult_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    fee DECIMAL(10,2) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
);
