<?php
/**
 * Master Logic Audit Script
 * Runs a full check on all critical system constraints.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "=== MASTER LOGIC AUDIT ===\n";
    $errors = 0;

    // 1. Borrowing Limits
    $sqlLimit = "
        SELECT u.user_id, u.borrow_limit, COUNT(br.borrow_id) as active_borrows
        FROM users u
        LEFT JOIN borrow_records br ON u.user_id = br.user_id AND br.status IN ('borrowed', 'overdue')
        GROUP BY u.user_id, u.borrow_limit
        HAVING active_borrows > u.borrow_limit
    ";
    $overLimit = $pdo->query($sqlLimit)->fetchAll();
    if (count($overLimit) > 0) {
        echo "[FAIL] " . count($overLimit) . " users exceeded borrow limits.\n";
        $errors++;
    } else {
        echo "[PASS] Borrowing limits respected.\n";
    }

    // 2. Book Status vs Quantity
    $qAvailableButEmpty = $pdo->query("SELECT COUNT(*) FROM books WHERE quantity <= 0 AND status = 'available'")->fetchColumn();
    $qBorrowedButHasStock = $pdo->query("SELECT COUNT(*) FROM books WHERE quantity > 0 AND status = 'borrowed'")->fetchColumn();
    if ($qAvailableButEmpty > 0 || $qBorrowedButHasStock > 0) {
        echo "[FAIL] Book status mismatch. (Available but empty: $qAvailableButEmpty, Borrowed but has stock: $qBorrowedButHasStock)\n";
        $errors++;
    } else {
        echo "[PASS] Book quantity and status are perfectly synced.\n";
    }

    // 3. Chronological Logic (Borrows)
    $invalidDates = $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE return_date < borrow_date")->fetchColumn();
    if ($invalidDates > 0) {
        echo "[FAIL] $invalidDates borrow records have return_date before borrow_date.\n";
        $errors++;
    } else {
        echo "[PASS] Borrow dates are chronologically correct.\n";
    }

    // 4. Chronological Logic (Reviews)
    $earlyReviews = $pdo->query("
        SELECT COUNT(*) 
        FROM book_reviews rev
        JOIN borrow_records br ON rev.book_id = br.book_id AND rev.user_id = br.user_id
        WHERE br.status = 'returned' AND rev.created_at < br.returned_at
    ")->fetchColumn();
    if ($earlyReviews > 0) {
        echo "[FAIL] $earlyReviews reviews were written BEFORE the book was returned.\n";
        $errors++;
    } else {
        echo "[PASS] Review chronological order is correct.\n";
    }

    // 5. Unique Review Constraint
    $dupReviews = $pdo->query("
        SELECT book_id, user_id, COUNT(*) as rev_count
        FROM book_reviews GROUP BY book_id, user_id HAVING rev_count > 1
    ")->fetchAll();
    if (count($dupReviews) > 0) {
        echo "[FAIL] " . count($dupReviews) . " duplicate reviews found.\n";
        $errors++;
    } else {
        echo "[PASS] Unique review per user per book constraint respected.\n";
    }

    // 6. Relational Integrity (Orphans)
    $orphanBorrows = $pdo->query("SELECT COUNT(*) FROM borrow_records br LEFT JOIN users u ON br.user_id = u.user_id LEFT JOIN books b ON br.book_id = b.book_id WHERE u.user_id IS NULL OR b.book_id IS NULL")->fetchColumn();
    $orphanReviews = $pdo->query("SELECT COUNT(*) FROM book_reviews rev LEFT JOIN users u ON rev.user_id = u.user_id LEFT JOIN books b ON rev.book_id = b.book_id WHERE u.user_id IS NULL OR b.book_id IS NULL")->fetchColumn();
    $orphanMessages = $pdo->query("SELECT COUNT(*) FROM ticket_messages tm LEFT JOIN support_tickets st ON tm.ticket_id = st.id WHERE st.id IS NULL")->fetchColumn();
    
    if ($orphanBorrows > 0 || $orphanReviews > 0 || $orphanMessages > 0) {
        echo "[FAIL] Orphan records found (Borrows: $orphanBorrows, Reviews: $orphanReviews, Messages: $orphanMessages).\n";
        $errors++;
    } else {
        echo "[PASS] Relational integrity (No orphan records) is intact.\n";
    }

    // 7. Role Constraint (Only students borrow)
    $adminBorrows = $pdo->query("SELECT COUNT(*) FROM borrow_records br JOIN users u ON br.user_id = u.user_id WHERE u.role != 'student'")->fetchColumn();
    if ($adminBorrows > 0) {
        echo "[FAIL] $adminBorrows borrow records belong to non-students.\n";
        $errors++;
    } else {
        echo "[PASS] Only students have borrow records.\n";
    }

    echo "\n=== AUDIT SUMMARY ===\n";
    if ($errors === 0) {
        echo "ALL SYSTEMS GO! 100% Logic Integrity Verified.\n";
    } else {
        echo "FOUND $errors LOGIC ERRORS. Fixes required.\n";
    }

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
