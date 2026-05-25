<?php
/**
 * Master Business Logic Deep-Dive Audit
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "=== MASTER BUSINESS LOGIC DEEP-DIVE ===\n\n";

    // 1. Inventory Integrity Check
    // Stock on shelf + Active/Pending/Overdue borrows should ideally match total from approved imports?
    // Let's check if any book has more borrowed records than its 'initial' capacity if we were to reconstruct it.
    echo "[Checking Inventory Flow]\n";
    $sqlInv = "
        SELECT b.book_id, b.title, b.quantity as shelf_qty, 
               (SELECT COUNT(*) FROM borrow_records WHERE book_id = b.book_id AND status IN ('borrowed', 'overdue', 'pending')) as active_borrows
        FROM books b
    ";
    $books = $pdo->query($sqlInv)->fetchAll();
    $invErrors = 0;
    foreach($books as $b) {
        if ($b['shelf_qty'] < 0) {
             echo "   - ERROR: Book ID {$b['book_id']} has negative quantity!\n";
             $invErrors++;
        }
    }
    if ($invErrors === 0) echo "   -> PASS: No negative stock detected.\n\n";

    // 2. User Permission Consistency
    echo "[Checking Restricted Actions]\n";
    // Check if any LOCKED users have PENDING borrow requests (they shouldn't be allowed to request if locked)
    $sqlLockedPending = "
        SELECT COUNT(*) 
        FROM borrow_records br
        JOIN users u ON br.user_id = u.user_id
        WHERE u.account_status = 'locked' AND br.status = 'pending'
    ";
    $lockedPendingCount = $pdo->query($sqlLockedPending)->fetchColumn();
    echo "   - Locked users with pending requests: $lockedPendingCount\n";
    if ($lockedPendingCount > 0) echo "     (Proposal: System should automatically reject/block requests from locked users)\n";

    // 3. Review Validity
    echo "[Checking Review Integrity]\n";
    // Rating distribution check
    $sqlRating = "SELECT rating, COUNT(*) as cnt FROM book_reviews GROUP BY rating ORDER BY rating ASC";
    $ratings = $pdo->query($sqlRating)->fetchAll();
    echo "   - Rating distribution: ";
    foreach($ratings as $r) echo "{$r['rating']}★({$r['cnt']}) ";
    echo "\n\n";

    // 4. Ticket Response Logic
    echo "[Checking Support Responsiveness]\n";
    // Open tickets older than 3 days
    $sqlSlowTickets = "SELECT COUNT(*) FROM support_tickets WHERE status = 'open' AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)";
    $slowTickets = $pdo->query($sqlSlowTickets)->fetchColumn();
    echo "   - Unanswered tickets > 3 days: $slowTickets\n\n";

    // 5. Notification Saturation
    echo "[Checking Notification Health]\n";
    $totalNoti = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $unreadNoti = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND is_deleted = 0")->fetchColumn();
    echo "   - Total Notifications: $totalNoti ($unreadNoti unread)\n";
    if ($totalNoti > 500) echo "     (Proposal: Implement a cleanup job for read notifications older than 30 days)\n\n";

    // 6. DB Schema Constraints check (Engine & FKs)
    echo "[Checking Database Engine]\n";
    $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'library_db' AND TABLE_NAME = 'books'")->fetchColumn();
    echo "   - Table Engine: $engine\n";
    if ($engine !== 'InnoDB') echo "     (Proposal: Switch to InnoDB to enforce Foreign Key constraints strictly)\n";

    echo "\n=== DEEP-DIVE COMPLETE ===\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
