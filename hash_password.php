<?php
// One-time helper: visit this page once to generate a bcrypt hash, paste it into
// sql/seed_admin.sql in place of :PASSWORD_HASH:, run the SQL, then DELETE this file.
$password = 'admin1234';
echo password_hash($password, PASSWORD_BCRYPT);
