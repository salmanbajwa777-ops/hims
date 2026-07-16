-- Admin-curated city/area reference lists for patient registration.
-- Receptionists can quick-add a missing area inline (status='pending'), usable immediately;
-- admin reviews/approves/merges on locations.php. See HMIS-PHP-PLAN.md for the decision record.

CREATE TABLE IF NOT EXISTS cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    status ENUM('active','pending') NOT NULL DEFAULT 'active',
    added_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_area_per_city (city_id, name)
);

INSERT INTO cities (name) VALUES ('Rawalpindi'), ('Islamabad'), ('Lahore');
