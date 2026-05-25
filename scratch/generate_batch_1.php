<?php
/**
 * Data Generation Script - Batch 1/10
 * Goal: 1 Admin, 10 Students, 100 Books, 50 Borrow Records, 20 Reviews.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 1: Starting data insertion...\n";

    // 1. Create Admin
    $passwordHash = password_hash('password123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute(['admin_1', 'admin1@hdpe.edu.vn', $passwordHash, 'Nguyễn Quản Lý', 'admin', 'Thủ Thư Số 1']);
    $adminId = $pdo->lastInsertId();
    echo "Created 1 Admin (ID: $adminId)\n";

    // 2. Create 10 Students
    $studentIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $stmt->execute([
            "student_$i", 
            "student$i@hdpe.edu.vn", 
            $passwordHash, 
            "Sinh Viên Batch 1 - Số $i", 
            "student", 
            "SV$i",
        ]);
        $studentIds[] = $pdo->lastInsertId();
    }
    echo "Created 10 Students.\n";

    // 3. Create 100 Books
    $categories = $pdo->query("SELECT name FROM book_categories")->fetchAll(PDO::FETCH_COLUMN);
    $bookIds = [];
    $stmtBook = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $bookData = [
        "Lập trình PHP căn bản", "Trần Văn A", "Kỹ thuật",
        "Tâm lý học hành vi", "Nguyễn Thị B", "Tâm lý / Sức khỏe",
        "Kinh tế vĩ mô", "Lê Văn C", "Kinh tế / Kinh doanh",
        "Văn học hiện đại Việt Nam", "Nhiều tác giả", "Văn học Việt Nam",
        "Cơ sở dữ liệu nâng cao", "Phạm Văn D", "Công nghệ thông tin",
        "Tiếng Anh giao tiếp", "Trương Thị E", "Ngoại ngữ",
        "Lịch sử thế giới", "Hồ Văn F", "Lịch sử",
        "Toán học cao cấp", "Đỗ Văn G", "Toán học",
        "Dinh dưỡng và sức khỏe", "Hoàng Thị H", "Sức khỏe",
        "Nghệ thuật giao tiếp", "Bùi Văn I", "Kỹ năng sống"
    ];

    for ($i = 1; $i <= 100; $i++) {
        $base = $bookData[array_rand($bookData, 1)]; // Not perfect but enough for fake data
        $title = $bookData[($i % 10) * 3] . " (Tập " . ceil($i / 10) . ")";
        $author = $bookData[($i % 10) * 3 + 1];
        $cat = $bookData[($i % 10) * 3 + 2];
        $qty = rand(1, 10);
        $stmtBook->execute([
            $title,
            $author,
            "ISBN-B1-" . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
            $cat,
            "Mô tả chi tiết cho cuốn sách $title. Đây là dữ liệu mẫu cho batch 1.",
            "NXB Giáo Dục",
            rand(2010, 2024),
            $qty,
            'available'
        ]);
        $bookIds[] = $pdo->lastInsertId();
    }
    echo "Created 100 Books.\n";

    // 4. Create 50 Borrow Records
    $stmtBorrow = $pdo->prepare("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    for ($i = 0; $i < 50; $i++) {
        $bId = $bookIds[array_rand($bookIds)];
        $uId = $studentIds[array_rand($studentIds)];
        $borrowDate = date('Y-m-d', strtotime('-' . rand(1, 30) . ' days'));
        $returnDate = date('Y-m-d', strtotime($borrowDate . ' + 14 days'));
        
        $statuses = ['borrowed', 'returned', 'overdue'];
        $status = $statuses[array_rand($statuses)];
        
        $stmtBorrow->execute([$bId, $uId, $borrowDate, $returnDate, $status]);
    }
    echo "Created 50 Borrow Records.\n";

    // 5. Create 20 Reviews
    $stmtReview = $pdo->prepare("INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
    $reviewCount = 0;
    $pairs = []; // Track to avoid UNIQUE constraint violation
    while ($reviewCount < 20) {
        $bId = $bookIds[array_rand($bookIds)];
        $uId = $studentIds[array_rand($studentIds)];
        
        if (isset($pairs["$bId-$uId"])) continue;
        $pairs["$bId-$uId"] = true;

        $stmtReview->execute([$bId, $uId, rand(3, 5), "Sách rất hay và bổ ích. Nội dung trình bày dễ hiểu. (Batch 1 Review)"]);
        $reviewCount++;
    }
    echo "Created 20 Reviews.\n";

    echo "Batch 1: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
