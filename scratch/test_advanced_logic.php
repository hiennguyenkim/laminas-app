<?php
/**
 * Advanced Logic Audit Script
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "--- ADVANCED LOGIC AUDIT ---\n";

    // 1. Check Borrowing Limits
    // Compare active borrows (borrowed + overdue) vs borrow_limit per user
    $sqlLimit = "
        SELECT u.user_id, u.borrow_limit, COUNT(br.borrow_id) as active_borrows
        FROM users u
        LEFT JOIN borrow_records br ON u.user_id = br.user_id AND br.status IN ('borrowed', 'overdue')
        GROUP BY u.user_id, u.borrow_limit
        HAVING active_borrows > u.borrow_limit
    ";
    $overLimitUsers = $pdo->query($sqlLimit)->fetchAll();
    echo "1. Users exceeding borrow limit: " . count($overLimitUsers) . "\n";
    if (count($overLimitUsers) > 0) {
        foreach ($overLimitUsers as $ou) {
            echo "   - User {$ou['user_id']} has {$ou['active_borrows']} active borrows (Limit: {$ou['borrow_limit']})\n";
        }
    }

    // 2. Check User Lock Logic
    // If account_status = 'locked', must have lock_reason and locked_at
    $sqlLocked = "SELECT COUNT(*) FROM users WHERE account_status = 'locked' AND (lock_reason IS NULL OR locked_at IS NULL)";
    $invalidLocks = $pdo->query($sqlLocked)->fetchColumn();
    echo "2. Locked users missing reason/timestamp: " . $invalidLocks . "\n";

    // 3. Check Duplicate Reviews (1 book, 1 user = 1 review)
    $sqlDupRev = "
        SELECT book_id, user_id, COUNT(*) as rev_count
        FROM book_reviews
        GROUP BY book_id, user_id
        HAVING rev_count > 1
    ";
    $dupReviews = $pdo->query($sqlDupRev)->fetchAll();
    echo "3. Duplicate reviews (same user & book): " . count($dupReviews) . "\n";

    // 4. Ticket Relational Integrity
    // Any message pointing to a non-existent ticket?
    $sqlOrphanMsg = "
        SELECT COUNT(*) FROM ticket_messages tm 
        LEFT JOIN support_tickets st ON tm.ticket_id = st.id 
        WHERE st.id IS NULL
    ";
    $orphanMsgs = $pdo->query($sqlOrphanMsg)->fetchColumn();
    echo "4. Orphaned ticket messages: " . $orphanMsgs . "\n";

    // 5. Check if any 'pending' import actually added quantity prematurely
    // (In this system, 'approved' imports add to quantity. Pending should not. Hard to audit without history logs, but we can check if statuses are valid)
    $invalidImportStatuses = $pdo->query("SELECT COUNT(*) FROM book_imports WHERE status NOT IN ('pending', 'approved', 'rejected')")->fetchColumn();
    echo "5. Invalid book import statuses: " . $invalidImportStatuses . "\n";

    echo "--- AUDIT COMPLETE ---\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
