<?php
$pdo = new PDO('mysql:host=localhost;dbname=library_db;charset=utf8mb4', 'root', '');
// Check if any returned book does not have returned_at
$q1 = $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE status = 'returned' AND returned_at IS NULL")->fetchColumn();
echo "Returned without returned_at: " . $q1 . "\n";

// Check if any borrowed book has a return_date in the past (should be overdue)
$q2 = $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE status = 'borrowed' AND return_date < CURDATE()")->fetchColumn();
echo "Borrowed but past return date: " . $q2 . "\n";

// Check if any overdue book has a return_date in the future
$q3 = $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE status = 'overdue' AND return_date >= CURDATE()")->fetchColumn();
echo "Overdue but future return date: " . $q3 . "\n";

// Check book quantity vs status
$q4 = $pdo->query("SELECT COUNT(*) FROM books WHERE quantity > 0 AND status = 'borrowed'")->fetchColumn();
echo "Quantity > 0 but borrowed: " . $q4 . "\n";
$q5 = $pdo->query("SELECT COUNT(*) FROM books WHERE quantity <= 0 AND status = 'available'")->fetchColumn();
echo "Quantity <= 0 but available: " . $q5 . "\n";
