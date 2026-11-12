-- Saved lab bill rates (2026-11-12). One row per test name; auto-fills the Amount on Generate Lab Bill.
-- Rates are only written when staff tick "save this rate" in the prompt on Save. Run ONCE, before pushing the PHP
-- (the page also works without the table, it just never offers/loads rates).
CREATE TABLE IF NOT EXISTS lab_test_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_key VARCHAR(150) NOT NULL,          -- lowercased test title, the match key
    title VARCHAR(150) NOT NULL,             -- title as last typed
    amount DECIMAL(10,2) NOT NULL,
    updated_by_id INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_rate_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
