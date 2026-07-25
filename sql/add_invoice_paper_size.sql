-- Per-doctor consultation-invoice paper size.
--
-- Some doctors want the OPD/consultation slip printed on A4 (a full-page
-- document), others on the original A5 (compact receipt). The size is a
-- property of the DOCTOR, chosen once on the Staff & Doctors page — reception
-- never picks a size; the slip prints in the visiting doctor's saved size
-- automatically (see checkout.php's print branch + views/invoice_print_partial.php).
--
-- A5 is the default so every existing doctor keeps today's exact slip until an
-- admin opts them into A4. The column lives on `users` (not just doctors) because
-- that's the account table; it's simply ignored for non-doctor roles, whose
-- visits never raise a consultation slip.
--
-- Safe to run more than once: the column is added only if it doesn't exist yet.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'invoice_paper_size'
);

SET @ddl := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN invoice_paper_size ENUM('A5','A4') NOT NULL DEFAULT 'A5' AFTER specialty",
    'SELECT "invoice_paper_size already exists" AS note'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Every existing account defaults to A5 (the original slip).
UPDATE users SET invoice_paper_size = 'A5' WHERE invoice_paper_size IS NULL;
