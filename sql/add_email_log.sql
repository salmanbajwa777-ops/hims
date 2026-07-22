-- Email notification log. Every send attempt (sent / failed / skipped) is
-- recorded here so a silent SMTP failure is diagnosable from phpMyAdmin.
-- Run in phpMyAdmin against u402528120_hmis BEFORE deploying the mailer code.

CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipients VARCHAR(500) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    context VARCHAR(100) NULL,          -- e.g. 'invoice:1202607', 'refund:RF-2026-0021', 'welcome:user#14'
    status ENUM('sent','failed','skipped') NOT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_created (status, created_at)
);
