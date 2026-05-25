<?php
$pdo = new PDO('mysql:host=localhost;dbname=library_db;charset=utf8mb4', 'root', '');
// Check 1: Pending borrows for locked users
$q1 = $pdo->query("SELECT COUNT(*) FROM borrow_records br JOIN users u ON br.user_id = u.user_id WHERE u.account_status = 'locked' AND br.status = 'pending'")->fetchColumn();
echo "Locked users with pending borrows: " . $q1 . "\n";

// Check 2: Books with zero quantity but 'available' status
$q2 = $pdo->query("SELECT COUNT(*) FROM books WHERE quantity <= 0 AND status = 'available'")->fetchColumn();
echo "Zero qty available books: " . $q2 . "\n";

// Check 3: Reviews from users who never borrowed that book
$q3 = $pdo->query("SELECT COUNT(*) FROM book_reviews rev LEFT JOIN borrow_records br ON rev.book_id = br.book_id AND rev.user_id = br.user_id WHERE br.borrow_id IS NULL")->fetchColumn();
echo "Reviews without borrows: " . $q3 . "\n";

// Check 4: Any ticket without messages
$q4 = $pdo->query("SELECT COUNT(*) FROM support_tickets st LEFT JOIN ticket_messages tm ON st.id = tm.ticket_id WHERE tm.id IS NULL")->fetchColumn();
echo "Tickets without messages: " . $q4 . "\n";

// Check 5: Duplicate active borrows for same user & book
$q5 = $pdo->query("SELECT user_id, book_id, COUNT(*) as cnt FROM borrow_records WHERE status IN ('borrowed', 'overdue') GROUP BY user_id, book_id HAVING cnt > 1")->fetchAll();
echo "Duplicate active borrows: " . count($q5) . "\n";
