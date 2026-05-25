<?php
/**
 * Data Generation Script - Batch 3/10 (Expanded)
 * Goal: 10 Students, 100 Books, 5 Announcements, 10 Tickets, 20 Notifications.
 * Realistic Vietnamese Data & Password: Admin@123
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 3: Starting expanded data insertion...\n";

    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    
    // 1. Students (21-30)
    $firstNames = ['Trịnh', 'Lý', 'Đoàn', 'Dương', 'Lương', 'Huỳnh', 'Cao', 'Thái', 'Lâm', 'Ngô'];
    $middleNames = ['Quốc', 'Thị', 'Hoàng', 'Minh', 'Hồng', 'Thành', 'Kim', 'Nhật', 'Thế', 'Vĩnh'];
    $lastNames = ['Kiệt', 'Anh', 'Huy', 'Tâm', 'Thảo', 'Quân', 'Yến', 'Phát', 'Trung', 'Hà'];

    $studentIds = [];
    for ($i = 21; $i <= 30; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $middleNames[array_rand($middleNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $username = "student_$i";
        $email = "sv_$i@student.hdpe.edu.vn";
        $dob = rand(1999, 2005) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '07' . rand(10000000, 99999999);
        
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, date_of_birth, phone, created_at) VALUES (?, ?, ?, ?, 'student', ?, ?, ?, NOW())");
        $stmt->execute([$username, $email, $passwordHash, $fullName, "User$i", $dob, $phone]);
        $studentIds[] = $pdo->lastInsertId();
    }
    echo "Created 10 Students.\n";

    // 2. Books (201-300)
    $bookTemplates = [
        ['Sáng tạo thuật toán', 'Steven Skiena', 'Công nghệ thông tin'],
        ['Hoàng tử bé', 'Antoine de Saint-Exupéry', 'Văn học nước ngoài'],
        ['Thế giới phẳng', 'Thomas L. Friedman', 'Khoa học'],
        ['Nhà giả kim', 'Paulo Coelho', 'Văn học nước ngoài'],
        ['Dạy con làm giàu', 'Robert Kiyosaki', 'Kinh tế / Kinh doanh'],
        ['Cho tôi xin một vé đi tuổi thơ', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam'],
        ['Đọc vị bất kỳ ai', 'David J. Lieberman', 'Tâm lý / Sức khỏe'],
        ['Đại Việt sử ký toàn thư', 'Ngô Sĩ Liên', 'Lịch sử'],
        ['Toán học sơ cấp', 'Nhiều tác giả', 'Toán học'],
        ['Yoga cho mọi nhà', 'Nhiều tác giả', 'Sức khỏe']
    ];

    $bookIds = [];
    for ($i = 201; $i <= 300; $i++) {
        $tpl = $bookTemplates[($i-201)%10];
        $title = $tpl[0] . " - Phiên bản đặc biệt #" . ($i-200);
        $author = $tpl[1];
        $cat = $tpl[2];
        
        $stmt = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, 'NXB Tổng Hợp', ?, ?, 'available', NOW())");
        $stmt->execute([
            $title, $author, "ISBN-B3-" . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
            $cat, "Một cuốn sách tuyệt vời về $cat, phù hợp cho mọi đối tượng độc giả muốn tìm hiểu kiến thức về $author.",
            rand(2018, 2024), rand(3, 12)
        ]);
        $bookIds[] = $pdo->lastInsertId();
    }
    echo "Created 100 Books.\n";

    // 3. Announcements (5)
    $annTypes = ['event', 'contest', 'holiday', 'general'];
    $adminId = 1; // Existing admin
    for ($i = 1; $i <= 5; $i++) {
        $type = $annTypes[array_rand($annTypes)];
        $stmt = $pdo->prepare("INSERT INTO announcements (title, content, type, created_by, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->execute([
            "Thông báo số $i: Chương trình thư viện Batch 3",
            "Nội dung thông báo quan trọng về $type dành cho tất cả sinh viên. Vui lòng theo dõi thường xuyên để cập nhật thông tin.",
            $type, $adminId
        ]);
    }
    echo "Created 5 Announcements.\n";

    // 4. Support Tickets (10)
    $ticketCats = ['lost_item', 'damaged_book', 'card_issue', 'other'];
    for ($i = 0; $i < 10; $i++) {
        $uId = $studentIds[array_rand($studentIds)];
        $cat = $ticketCats[array_rand($ticketCats)];
        
        $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, category, title, description, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW())");
        $stmt->execute([
            $uId, $cat, "Hỏi về vấn đề $cat #$i",
            "Em có gặp một chút khó khăn về $cat, mong thủ thư hỗ trợ giúp em.",
            'open'
        ]);
        $tId = $pdo->lastInsertId();
        
        // Add a message for each ticket
        $stmtMsg = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, 'user', ?, NOW())");
        $stmtMsg->execute([$tId, $uId, "Em xin cảm ơn ạ!"]);
    }
    echo "Created 10 Support Tickets.\n";

    // 5. Notifications (20)
    $notiTypes = ['system', 'general', 'borrow'];
    for ($i = 0; $i < 20; $i++) {
        $uId = $studentIds[array_rand($studentIds)];
        $type = $notiTypes[array_rand($notiTypes)];
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->execute([
            $uId, "Thông báo Batch 3 #$i",
            "Chào mừng bạn đến với hệ thống thư viện. Đây là thông báo tự động đợt 3.",
            $type
        ]);
    }
    echo "Created 20 Notifications.\n";

    echo "Batch 3: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
