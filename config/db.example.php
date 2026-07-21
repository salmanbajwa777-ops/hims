<?php
// All dates/times in HMIS are Pakistan Standard Time (UTC+5).
date_default_timezone_set('Asia/Karachi');

$host = 'localhost';
$dbname = 'u402528120_hims';
$username = 'u402528120_hims';
$password = 'CHANGE_ME';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Keep MySQL NOW()/CURDATE()/CURRENT_TIMESTAMP on PKT too. Numeric offset,
    // not 'Asia/Karachi' — named zones need the mysql.time_zone tables loaded,
    // which shared hosting usually lacks. Pakistan has no DST, so +05:00 holds.
    $pdo->exec("SET time_zone = '+05:00'");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
