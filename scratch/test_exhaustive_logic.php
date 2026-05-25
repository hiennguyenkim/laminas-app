<?php
/**
 * Exhaustive Logic Audit Script - Edge Cases & Chronological Order
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "--- EXHAUSTIVE EDGE CASE AUDIT ---\n";

    // 1. Chronological order: Return date must be >= Borrow date
    $sqlDates = "SELECT COUNT(*) FROM borrow_records WHERE return_date < borrow_date";
    $invalidDates = $pdo->query($sqlDates)->fetchColumn();
    echo "1. Borrow records with return_date BEFORE borrow_date: " . $invalidDates . "\n";

    // 2. Chronological order: Ticket messages sent BEFORE ticket created
    $sqlTicketDates = "
        SELECT COUNT(*) 
        FROM ticket_messages tm
        JOIN support_tickets st ON tm.ticket_id = st.id
        WHERE tm.sent_at < st.created_at
    ";
    $invalidTickets = $pdo->query($sqlTicketDates)->fetchColumn();
    echo "2. Ticket messages sent before ticket creation: " . $invalidTickets . "\n";

    // 3. User Lock Inconsistencies: Active users with a lock reason/time
    $sqlActiveLocked = "
        SELECT COUNT(*) 
        FROM users 
        WHERE account_status = 'active' AND (lock_reason IS NOT NULL OR locked_at IS NOT NULL)
    ";
    $activeWithLockData = $pdo->query($sqlActiveLocked)->fetchColumn();
    echo "3. Active users holding residual lock data: " . $activeWithLockData . "\n";

    // 4. Role constraints: Only 'student' should have borrow records
    $sqlAdminBorrows = "
        SELECT COUNT(*) 
        FROM borrow_records br
        JOIN users u ON br.user_id = u.user_id
        WHERE u.role != 'student'
    ";
    $adminBorrows = $pdo->query($sqlAdminBorrows)->fetchColumn();
    echo "4. Borrow records belonging to non-students (e.g., admins): " . $adminBorrows . "\n";

    // 5. Review Constraints: Rating must be exactly between 1 and 5
    $sqlBadRatings = "
        SELECT COUNT(*) 
        FROM book_reviews 
        WHERE rating < 1 OR rating > 5
    ";
    $badRatings = $pdo->query($sqlBadRatings)->fetchColumn();
    echo "5. Book reviews with out-of-bounds ratings (<1 or >5): " . $badRatings . "\n";

    // 6. Chronological Order: Reviews created BEFORE the book was returned
    $sqlEarlyReviews = "
        SELECT COUNT(*) 
        FROM book_reviews rev
        JOIN borrow_records br ON rev.book_id = br.book_id AND rev.user_id = br.user_id
        WHERE br.status = 'returned' AND rev.created_at < br.returned_at
    ";
    $earlyReviews = $pdo->query($sqlEarlyReviews)->fetchColumn();
    echo "6. Reviews created BEFORE the book was actually returned: " . $earlyReviews . "\n";

    echo "--- AUDIT COMPLETE ---\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
