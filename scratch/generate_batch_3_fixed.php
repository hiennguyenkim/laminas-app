<?php
/**
 * Data Generation Script - Batch 3/10 (FIXED)
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Clean up partial Batch 3 run if needed (Optional, but since it failed at tickets, books/users might be there)
    // For simplicity, we just adjust the starting point or handle existing
    echo "Batch 3 (Fixed): Resuming data insertion...\n";

    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $studentIds = [];
    
    // Check for existing students from failed run
    $existing = $pdo->query("SELECT user_id FROM users WHERE username LIKE 'student_%' AND user_id > 21")->fetchAll(PDO::FETCH_COLUMN);
    if (count($existing) >= 10) {
        $studentIds = $existing;
        echo "Students already created, skipping.\n";
    } else {
        // ... Re-run student creation logic if needed ...
        // (Assuming partial run might have created some, but for consistency let's just fetch existing students)
        $studentIds = $pdo->query("SELECT user_id FROM users WHERE role = 'student' ORDER BY user_id DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    }

    // Support Tickets (10) - THE FIXED PART
    echo "Fixing Support Tickets insertion...\n";
    $ticketCats = ['lost_item', 'damaged_book', 'card_issue', 'other'];
    for ($i = 0; $i < 10; $i++) {
        $uId = $studentIds[array_rand($studentIds)];
        $cat = $ticketCats[array_rand($ticketCats)];
        
        // Correct number of tokens: 5 (?) vs variables: 5
        // Wait, the previous code had: INSERT INTO support_tickets (user_id, category, title, description, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW())
        // That's 5 '?' tokens but 6 columns mentioned. 'open' is a literal.
        // Let's use 4 tokens and 2 literals.
        $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, category, title, description, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW())");
        $stmt->execute([$uId, $cat, "Hỏi về vấn đề $cat #$i", "Em có gặp một chút khó khăn về $cat, mong thủ thư hỗ trợ giúp em."]);
        $tId = $pdo->lastInsertId();
        
        $stmtMsg = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, 'user', ?, NOW())");
        $stmtMsg->execute([$tId, $uId, "Em xin cảm ơn ạ!"]);
    }
    echo "Created 10 Support Tickets (Fixed).\n";

    // Notifications (20)
    $notiTypes = ['system', 'general', 'borrow'];
    for ($i = 0; $i < 20; $i++) {
        $uId = $studentIds[array_rand($studentIds)];
        $type = $notiTypes[array_rand($notiTypes)];
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->execute([$uId, "Thông báo Batch 3 #$i", "Chào mừng bạn đến với hệ thống thư viện.", $type]);
    }
    echo "Created 20 Notifications.\n";

    echo "Batch 3: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
