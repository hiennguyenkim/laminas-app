<?php
/**
 * Data Sanitization - Synchronize Emails
 * Goal: Ensure all emails match the pattern: {username}@{domain}
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Synchronizing Emails...\n";

    // Admin emails
    $stmtAdmin = $pdo->prepare("UPDATE users SET email = CONCAT(username, '@hdpe.edu.vn') WHERE role = 'admin'");
    $stmtAdmin->execute();
    echo "Synchronized " . $stmtAdmin->rowCount() . " Admin emails.\n";

    // Student emails
    $stmtStudent = $pdo->prepare("UPDATE users SET email = CONCAT(username, '@student.hdpe.edu.vn') WHERE role = 'student'");
    $stmtStudent->execute();
    echo "Synchronized " . $stmtStudent->rowCount() . " Student emails.\n";

    echo "Email Synchronization COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
