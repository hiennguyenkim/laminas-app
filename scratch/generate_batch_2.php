<?php
/**
 * Data Generation Script - Batch 2/10
 * Goal: 10 Students (Realistic), 100 Books (Realistic), 50 Borrow Records, 20 Reviews.
 * Password: Admin@123
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 2: Starting realistic data insertion...\n";

    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, date_of_birth, phone, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    // 1. Realistic Student Data
    $firstNames = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Phan', 'Vũ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ'];
    $middleNames = ['Văn', 'Thị', 'Minh', 'Anh', 'Đức', 'Hoàng', 'Thanh', 'Tú', 'Ngọc', 'Quang'];
    $lastNames = ['Hùng', 'Hoa', 'Dũng', 'Lan', 'Nam', 'Mai', 'Thắng', 'Cúc', 'Bình', 'Liên'];

    $studentIds = [];
    for ($i = 11; $i <= 20; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $middleNames[array_rand($middleNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $username = "student_$i";
        $email = "sv_$i" . "@student.hdpe.edu.vn";
        $dob = rand(2000, 2005) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '09' . rand(10000000, 99999999);
        
        $stmtUser->execute([
            $username, $email, $passwordHash, $fullName, 'student', "Nick$i", $dob, $phone
        ]);
        $studentIds[] = $pdo->lastInsertId();
    }
    echo "Created 10 Realistic Students.\n";

    // 2. Realistic Book Data
    $stmtBook = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $bookTemplates = [
        ['Sổ tay Công nghệ Thông tin', 'James O\'Brien', 'Công nghệ thông tin'],
        ['Đắc Nhân Tâm', 'Dale Carnegie', 'Kỹ năng sống'],
        ['Lược sử Thời gian', 'Stephen Hawking', 'Khoa học'],
        ['Cha Giàu Cha Nghèo', 'Robert Kiyosaki', 'Kinh tế / Kinh doanh'],
        ['Suối Nguồn', 'Ayn Rand', 'Văn học nước ngoài'],
        ['Số Đỏ', 'Vũ Trọng Phụng', 'Văn học Việt Nam'],
        ['Tâm lý học Đám đông', 'Gustave Le Bon', 'Tâm lý / Sức khỏe'],
        ['Lịch sử Việt Nam từ nguồn gốc đến thế kỷ XIX', 'Đào Duy Anh', 'Lịch sử'],
        ['Toán học và những điều kỳ thú', 'Nhiều tác giả', 'Toán học'],
        ['Dinh dưỡng cho sức khỏe vàng', 'BS. Thu Hà', 'Sức khỏe']
    ];

    $bookIds = [];
    for ($i = 101; $i <= 200; $i++) {
        $tpl = $bookTemplates[array_rand($bookTemplates)];
        $title = $tpl[0] . " - Tái bản lần " . rand(1, 5);
        $author = $tpl[1];
        $category = $tpl[2];
        $qty = rand(2, 8);
        
        $stmtBook->execute([
            $title, $author, "ISBN-B2-" . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
            $category, "Cuốn sách cung cấp kiến thức chuyên sâu về $category. Nội dung được cập nhật mới nhất cho năm 2024.",
            "NXB Trẻ", rand(2015, 2024), $qty, 'available'
        ]);
        $bookIds[] = $pdo->lastInsertId();
    }
    echo "Created 100 Realistic Books.\n";

    // 3. Borrow Records (50)
    // Need student IDs from Batch 1 as well to make it realistic (more transactions per user)
    $allStudents = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
    $allBooks = $pdo->query("SELECT book_id FROM books")->fetchAll(PDO::FETCH_COLUMN);

    $stmtBorrow = $pdo->prepare("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    for ($i = 0; $i < 50; $i++) {
        $bId = $allBooks[array_rand($allBooks)];
        $uId = $allStudents[array_rand($allStudents)];
        $borrowDate = date('Y-m-d', strtotime('-' . rand(5, 60) . ' days'));
        $returnDate = date('Y-m-d', strtotime($borrowDate . ' + 14 days'));
        
        $statuses = ['borrowed', 'returned', 'overdue'];
        $status = $statuses[array_rand($statuses)];
        $stmtBorrow->execute([$bId, $uId, $borrowDate, $returnDate, $status]);
    }
    echo "Created 50 Borrow Records.\n";

    // 4. Reviews (20)
    $stmtReview = $pdo->prepare("INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
    $comments = [
        "Sách cực kỳ hay, rất đáng đọc.",
        "Kiến thức trong sách rất thực tế và dễ áp dụng.",
        "Nội dung hơi khó hiểu ở một số chương nhưng tổng thể vẫn rất tốt.",
        "Hình thức đẹp, nội dung phong phú.",
        "Tôi đã học được rất nhiều điều từ cuốn sách này."
    ];
    
    $reviewCount = 0;
    while ($reviewCount < 20) {
        $bId = $allBooks[array_rand($allBooks)];
        $uId = $allStudents[array_rand($allStudents)];
        
        // Skip if already reviewed (avoid UNIQUE error)
        $check = $pdo->prepare("SELECT COUNT(*) FROM book_reviews WHERE book_id = ? AND user_id = ?");
        $check->execute([$bId, $uId]);
        if ($check->fetchColumn() > 0) continue;

        $stmtReview->execute([$bId, $uId, rand(4, 5), $comments[array_rand($comments)]]);
        $reviewCount++;
    }
    echo "Created 20 Realistic Reviews.\n";

    echo "Batch 2: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
