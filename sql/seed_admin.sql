-- Seeded fallback admin: identifier "admin", password "admin1234"
-- Replace :PASSWORD_HASH: with the output of hash_password.php (see deploy notes),
-- then run this once against the hims database.
INSERT INTO users (name, email, phone, password, base_role, must_change_password)
SELECT 'System Admin', 'admin', NULL, ':PASSWORD_HASH:', 'ADMIN', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin');
