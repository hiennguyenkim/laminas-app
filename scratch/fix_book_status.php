<?php
/**
 * Data Sanitization - Fix Book Status vs Quantity
 * Goal: Ensure status perfectly reflects quantity (available if > 0, borrowed if = 0).
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Synchronizing Book Statuses with Quantities...\n";

    // 1. Fix books that have quantity > 0 but are marked as borrowed/unavailable (unless deliberately unavailable)
    // For our realistic data, we just assume > 0 means available.
    $stmtAvailable = $pdo->prepare("UPDATE books SET status = 'available' WHERE quantity > 0 AND status != 'unavailable'");
    $stmtAvailable->execute();
    $fixedToAvailable = $stmtAvailable->rowCount();

    // 2. Fix books that have quantity <= 0 but are not marked as borrowed
    $stmtBorrowed = $pdo->prepare("UPDATE books SET status = 'borrowed' WHERE quantity <= 0 AND status != 'unavailable'");
    $stmtBorrowed->execute();
    $fixedToBorrowed = $stmtBorrowed->rowCount();

    echo "Fixed $fixedToAvailable books (Set to Available because Qty > 0).\n";
    echo "Fixed $fixedToBorrowed books (Set to Borrowed because Qty <= 0).\n";
    echo "Status Synchronization COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
