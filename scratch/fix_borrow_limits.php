<?php
/**
 * Data Sanitization - Fix Borrowing Limits
 * Goal: Ensure no user exceeds their borrow_limit.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Fixing Borrowing Limits...\n";

    // Find users over limit
    $sqlLimit = "
        SELECT u.user_id, u.borrow_limit, COUNT(br.borrow_id) as active_borrows
        FROM users u
        LEFT JOIN borrow_records br ON u.user_id = br.user_id AND br.status IN ('borrowed', 'overdue')
        GROUP BY u.user_id, u.borrow_limit
        HAVING active_borrows > u.borrow_limit
    ";
    $overLimitUsers = $pdo->query($sqlLimit)->fetchAll();

    $stmtGetBorrows = $pdo->prepare("SELECT borrow_id, book_id FROM borrow_records WHERE user_id = ? AND status IN ('borrowed', 'overdue') ORDER BY borrow_date ASC");
    $stmtReturn = $pdo->prepare("UPDATE borrow_records SET status = 'returned', returned_at = NOW() WHERE borrow_id = ?");
    $stmtIncBook = $pdo->prepare("UPDATE books SET quantity = quantity + 1, status = 'available' WHERE book_id = ?");

    $totalFixed = 0;

    foreach ($overLimitUsers as $ou) {
        $uId = $ou['user_id'];
        $excess = $ou['active_borrows'] - $ou['borrow_limit'];
        
        // Fetch their active borrows
        $stmtGetBorrows->execute([$uId]);
        $borrows = $stmtGetBorrows->fetchAll();
        
        // "Return" the oldest ones until they are within limit
        for ($i = 0; $i < $excess; $i++) {
            if (isset($borrows[$i])) {
                $bId = $borrows[$i]['borrow_id'];
                $bookId = $borrows[$i]['book_id'];
                
                $stmtReturn->execute([$bId]);
                $stmtIncBook->execute([$bookId]);
                $totalFixed++;
            }
        }
    }

    echo "Successfully 'returned' $totalFixed excess books to respect borrowing limits.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
