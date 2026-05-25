<?php
/**
 * Data Generation Script - Batch 8/10
 * Goal: Generate ORGANIC, UNEVEN numbers to break the artificial "round number" pattern.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 8: Starting ORGANIC data insertion...\n";

    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    
    // 1. Organic Students
    // Generate a random number between 8 and 15
    $numStudents = rand(8, 15);
    $firstNames = ['Trần', 'Lý', 'Đoàn', 'Dương', 'Phan', 'Huỳnh', 'Đặng', 'Thái', 'Lâm', 'Hồ'];
    $middleNames = ['Hoàng', 'Thị', 'Quốc', 'Minh', 'Hồng', 'Thành', 'Kim', 'Nhật', 'Thế', 'Vĩnh'];
    $lastNames = ['Kiệt', 'Anh', 'Huy', 'Tâm', 'Thảo', 'Quân', 'Yến', 'Phát', 'Trung', 'Hà'];
    $nickSuffixes = ['Lười', 'Nhanh', 'Bé', 'Bự', 'Trùm', 'Pro', 'Cute'];

    $studentIds = [];
    $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, date_of_birth, phone, created_at) VALUES (?, ?, ?, ?, 'student', ?, ?, ?, ?)");
    
    for ($i = 0; $i < $numStudents; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $middleNames[array_rand($middleNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $uidStr = uniqid(); // Random string for username to avoid sequential student_XX
        $username = "hv_" . substr($uidStr, -5);
        $email = $username . "@student.hdpe.edu.vn";
        $dob = rand(1999, 2005) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '0' . rand(3,9) . rand(10000000, 99999999);
        
        $nickname = null;
        if (rand(1, 100) > 40) { 
            $firstName = explode(' ', $fullName)[2] ?? 'User';
            $nickname = $firstName . ' ' . $nickSuffixes[array_rand($nickSuffixes)];
        }

        // Randomize created_at over the last 90 days, not just NOW()
        $createdAt = date('Y-m-d H:i:s', time() - rand(0, 90 * 86400));

        $stmtUser->execute([$username, $email, $passwordHash, $fullName, $nickname, $dob, $phone, $createdAt]);
        $studentIds[] = $pdo->lastInsertId();
    }
    echo "Created $numStudents Organic Students.\n";

    // 2. Organic Books
    // Generate a random number between 83 and 117
    $numBooks = rand(83, 117);
    $bookTemplates = [
        ['Sự trỗi dậy của AI', 'Nhiều tác giả', 'Công nghệ thông tin'],
        ['Tiếng gọi nơi hoang dã', 'Jack London', 'Văn học nước ngoài'],
        ['Lược sử vạn vật', 'Bill Bryson', 'Khoa học'],
        ['Tư duy nhanh và chậm', 'Daniel Kahneman', 'Kinh tế / Kinh doanh'],
        ['Tâm lý học tội phạm', 'Stanton E. Samenow', 'Tâm lý / Sức khỏe'],
        ['Ông già và biển cả', 'Ernest Hemingway', 'Văn học nước ngoài'],
        ['Tuổi trẻ đáng giá bao nhiêu', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam'],
        ['Xác suất thống kê', 'Nguyễn Đình Trí', 'Toán học'],
        ['Bí quyết sống khỏe', 'Nhiều tác giả', 'Sức khỏe'],
        ['Khác biệt để bứt phá', 'Jason Fried', 'Kinh tế / Kinh doanh']
    ];

    $bookIds = [];
    $stmtBook = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, 'NXB Thời Đại', ?, ?, 'available', ?)");
    
    for ($i = 0; $i < $numBooks; $i++) {
        $tpl = $bookTemplates[array_rand($bookTemplates)];
        $title = $tpl[0] . (rand(0, 1) ? " (Bản bìa cứng)" : "");
        $author = $tpl[1];
        $cat = $tpl[2];
        $qty = rand(1, 14); // Some books only have 1 copy
        
        $createdAt = date('Y-m-d H:i:s', time() - rand(0, 60 * 86400));
        $isbnRand = str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

        $stmtBook->execute([
            $title, $author, "ISBN-" . rand(100, 999) . "-" . $isbnRand,
            $cat, "Ấn phẩm đặc biệt thuộc thể loại $cat. Phù hợp cho sinh viên nghiên cứu.",
            rand(2010, 2024), $qty, $createdAt
        ]);
        $bookIds[] = $pdo->lastInsertId();
    }
    echo "Created $numBooks Organic Books.\n";

    // 3. Book Imports (Uneven: 17 to 28)
    $numImports = rand(17, 28);
    $stmtImport = $pdo->prepare("INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, price, note, imported_by, status, import_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'purchase', ?, ?, 1, 'approved', ?, ?)");
    for ($i = 0; $i < $numImports; $i++) {
        $bId = $bookIds[array_rand($bookIds)];
        $stmtFind = $pdo->prepare("SELECT title, author, category FROM books WHERE book_id = ?");
        $stmtFind->execute([$bId]);
        $bData = $stmtFind->fetch();

        $invoice = "HD-B8-" . date('Ymd') . "-" . rand(100, 999);
        $price = rand(45, 320) * 1000; // 45k to 320k
        $qty = rand(5, 45); // Uneven quantities
        $daysAgo = rand(1, 60);
        $date = date('Y-m-d', strtotime("-$daysAgo days"));
        $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days +" . rand(1, 20) ." hours"));
        $note = rand(0,1) ? "Nhập bổ sung theo yêu cầu" : "Sách mua mới dự án 2024";

        $stmtImport->execute([
            $bId, $invoice, $bData['title'], $bData['author'], "ISBN-IMP-" . rand(1000, 9999),
            $bData['category'], "NXB Thời Đại", rand(2020, 2024), $qty, $price, $note, $date, $createdAt
        ]);
    }
    echo "Created $numImports Organic Book Import Records.\n";

    // 4. Borrow Records (Uneven: 42 to 73)
    $numBorrows = rand(42, 73);
    $allStudents = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
    $allBooks = $pdo->query("SELECT book_id FROM books")->fetchAll(PDO::FETCH_COLUMN);

    $stmtBorrow = $pdo->prepare("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, returned_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $borrowedCounts = [];

    for ($i = 0; $i < $numBorrows; $i++) {
        $bId = $allBooks[array_rand($allBooks)];
        $uId = $allStudents[array_rand($allStudents)];
        
        $statuses = ['borrowed', 'returned', 'returned', 'overdue', 'pending']; // Higher chance of returned
        $status = $statuses[array_rand($statuses)];

        $bTs = time() - rand(2, 60) * 86400; // 2 to 60 days ago
        $bDate = date('Y-m-d', $bTs);
        $rDate = date('Y-m-d', $bTs + 14 * 86400); 
        $retAt = null;

        if ($status === 'returned') {
            // Returned realistically (sometimes early, sometimes exactly on due date)
            $retTs = $bTs + rand(1, 14) * 86400 + rand(0, 86400); // 1 to 14 days later + random hours
            $retTs = min($retTs, time()); 
            $retAt = date('Y-m-d H:i:s', $retTs);
        } elseif ($status === 'borrowed') {
            if (strtotime($rDate) < time()) {
                $status = 'overdue';
            } else {
                $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
            }
        } elseif ($status === 'overdue') {
             if (strtotime($rDate) >= time()) {
                 $rDate = date('Y-m-d', time() - rand(1, 9) * 86400);
                 $bDate = date('Y-m-d', strtotime($rDate) - 14 * 86400);
             }
             $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
        }

        $createdAt = date('Y-m-d H:i:s', $bTs - rand(0, 3600)); // Created slightly before borrow date (or same day)
        $stmtBorrow->execute([$bId, $uId, $bDate, $rDate, $status, $retAt, $createdAt]);
    }
    echo "Created $numBorrows Organic Borrow Records.\n";

    // 5. Reviews (Uneven: 13 to 27)
    $numReviews = rand(13, 27);
    $stmtReview = $pdo->prepare("INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, ?)");
    $comments = [
        "Sách hơi dày nhưng nội dung chất lượng.",
        "Rất thích cách tác giả phân tích vấn đề.",
        "Cuốn này mình mượn 2 lần mới đọc xong, rất đáng thời gian bỏ ra.",
        "Phù hợp với ai đang bí ý tưởng làm báo cáo.",
        "Không hay như mình kỳ vọng, nhưng cũng ổn.",
        "Tuyệt vời!",
        "Ai có hứng thú với chủ đề này thì nhất định phải mượn nhé."
    ];
    
    $returnedRecords = $pdo->query("SELECT book_id, user_id, returned_at FROM borrow_records WHERE status = 'returned' ORDER BY RAND() LIMIT $numReviews")->fetchAll();
    
    $reviewCount = 0;
    foreach ($returnedRecords as $rec) {
        $bId = $rec['book_id'];
        $uId = $rec['user_id'];
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM book_reviews WHERE book_id = ? AND user_id = ?");
        $check->execute([$bId, $uId]);
        if ($check->fetchColumn() > 0) continue;

        $retTs = strtotime($rec['returned_at']);
        $revTs = $retTs + rand(60, 86400 * 5); // 1 minute to 5 days after returning
        $revTs = min($revTs, time());

        // Organic ratings (more 4s and 5s, fewer 1s and 2s)
        $ratings = [3, 4, 4, 4, 5, 5, 5, 5];
        $stmtReview->execute([$bId, $uId, $ratings[array_rand($ratings)], $comments[array_rand($comments)], date('Y-m-d H:i:s', $revTs)]);
        $reviewCount++;
    }
    echo "Created $reviewCount Organic Reviews.\n";

    // 6. Update Book quantities
    $stmtDecQty = $pdo->prepare("UPDATE books SET quantity = GREATEST(0, quantity - ?), status = CASE WHEN quantity - ? <= 0 THEN 'borrowed' ELSE status END WHERE book_id = ?");
    foreach ($borrowedCounts as $bId => $count) {
        $stmtDecQty->execute([$count, $count, $bId]);
    }

    echo "Batch 8: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
