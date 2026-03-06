<?php
// ============================================================
//  db.php  —  Database connection
//  Place this file in the same folder as register_event.php
//  Edit the 4 constants below to match your XAMPP setup
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'attendance_segregator');
define('DB_USER', 'root');       // default XAMPP user
define('DB_PASS', '');           // default XAMPP password (empty)

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<h2 style="color:red;font-family:sans-serif;">Database connection failed: '
        . htmlspecialchars($e->getMessage()) . '</h2>');
}
