<?php
/**
 * Data Generation Script - Batch 7/10
 * Goal: 10 Students, 100 Books, 50 Borrow Records, 20 Reviews, 20 Book Imports.
 * Strict Logic Enforcement: Mật khẩu 'Admin@123', Correct Borrow/Return Dates, Valid Review Logic.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 7: Starting strict data insertion...\n";

    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    
    // 1. Students (41-50)
    $firstNames = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Huỳnh', 'Hoàng', 'Phan', 'Vũ', 'Võ', 'Đặng'];
    $middleNames = ['Quang', 'Hải', 'Tuấn', 'Hồng', 'Kiều', 'Thúy', 'Thu', 'Phương', 'Bích', 'Trúc'];
    $lastNames = ['Anh', 'Bình', 'Châu', 'Diệp', 'Hạnh', 'Khánh', 'Lan', 'Nhung', 'Trâm', 'Uyên'];
    $nickSuffixes = ['Múp', 'Còm', 'Lì', 'Trầm Tính', 'Sôi Nổi', 'Chan', 'Kun', 'Mọt'];

    $studentIds = [];
    $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, date_of_birth, phone, created_at) VALUES (?, ?, ?, ?, 'student', ?, ?, ?, NOW())");
    
    for ($i = 41; $i <= 50; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $middleNames[array_rand($middleNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $username = "student_$i";
        $email = "sv_$i@student.hdpe.edu.vn";
        $dob = rand(1999, 2005) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '08' . rand(10000000, 99999999);
        
        $nickname = null;
        if (rand(1, 100) > 30) { // 70% chance to have a nickname
            $firstName = explode(' ', $fullName)[2] ?? 'User';
            $nickname = $firstName . ' ' . $nickSuffixes[array_rand($nickSuffixes)];
        }

        $stmtUser->execute([$username, $email, $passwordHash, $fullName, $nickname, $dob, $phone]);
        $studentIds[] = $pdo->lastInsertId();
    }
    echo "Created 10 Students.\n";

    // 2. Books (401-500)
    $bookTemplates = [
        ['Machine Learning Cơ bản', 'Andrew Ng', 'Công nghệ thông tin'],
        ['Tiếng Chim Hót Trong Bụi Mận Gai', 'Colleen McCullough', 'Văn học nước ngoài'],
        ['Vũ trụ song song', 'Michio Kaku', 'Khoa học'],
        ['Khởi nghiệp Tinh gọn', 'Eric Ries', 'Kinh tế / Kinh doanh'],
        ['Hồ sơ Tâm lý học', 'Nhiều tác giả', 'Tâm lý / Sức khỏe'],
        ['Cây Cam Ngọt Của Tôi', 'José Mauro de Vasconcelos', 'Văn học nước ngoài'],
        ['Tôi thấy hoa vàng trên cỏ xanh', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam'],
        ['Đại số tuyến tính', 'Nguyễn Đình Trí', 'Toán học'],
        ['Bệnh học cơ sở', 'NXB Y Học', 'Sức khỏe'],
        ['Marketing Căn bản', 'Philip Kotler', 'Kinh tế / Kinh doanh']
    ];

    $bookIds = [];
    $stmtBook = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, 'NXB Khoa Học', ?, ?, 'available', NOW())");
    
    for ($i = 401; $i <= 500; $i++) {
        $tpl = $bookTemplates[($i-401)%10];
        $title = $tpl[0] . " (Lần tái bản thứ " . rand(1, 10) . ")";
        $author = $tpl[1];
        $cat = $tpl[2];
        $qty = rand(3, 12);
        
        $stmtBook->execute([
            $title, $author, "ISBN-B7-" . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
            $cat, "Một trong những tựa sách nổi bật nhất về $cat. Được nhiều sinh viên yêu thích và mượn đọc.",
            rand(2010, 2024), $qty
        ]);
        $bookIds[] = $pdo->lastInsertId();
    }
    echo "Created 100 Books.\n";

    // 3. Book Imports (20)
    $stmtImport = $pdo->prepare("INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, price, note, imported_by, status, import_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'purchase', ?, 'Nhập kho đợt 7', 1, 'approved', ?, ?)");
    for ($i = 1; $i <= 20; $i++) {
        $bId = $bookIds[array_rand($bookIds)];
        $stmtFind = $pdo->prepare("SELECT title, author, category FROM books WHERE book_id = ?");
        $stmtFind->execute([$bId]);
        $bData = $stmtFind->fetch();

        $invoice = "HD-B7-" . date('Ymd') . "-" . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $price = rand(60000, 300000);
        $qty = rand(10, 50);
        $daysAgo = rand(1, 30);
        $date = date('Y-m-d', strtotime("-$daysAgo days"));
        $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days +2 hours"));
        
        $stmtImport->execute([
            $bId, $invoice, $bData['title'], $bData['author'], "ISBN-IMP-B7-" . rand(100, 999),
            $bData['category'], "NXB Khoa Học", rand(2020, 2024), $qty, $price, $date, $createdAt
        ]);
    }
    echo "Created 20 Book Import Records (Linked to new books).\n";

    // 4. Borrow Records (50)
    $allStudents = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
    $allBooks = $pdo->query("SELECT book_id FROM books")->fetchAll(PDO::FETCH_COLUMN);

    $stmtBorrow = $pdo->prepare("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, returned_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    // We will track which books are "borrowed" to ensure we decrement their quantity correctly at the end
    $borrowedCounts = [];

    for ($i = 0; $i < 50; $i++) {
        $bId = $allBooks[array_rand($allBooks)];
        $uId = $allStudents[array_rand($allStudents)];
        
        $statuses = ['borrowed', 'returned', 'overdue', 'pending'];
        $status = $statuses[array_rand($statuses)];

        $bTs = time() - rand(5, 40) * 86400; // 5 to 40 days ago
        $bDate = date('Y-m-d', $bTs);
        $rDate = date('Y-m-d', $bTs + 14 * 86400); // 14 days later
        $retAt = null;

        if ($status === 'returned') {
            // Must have returned_at between bDate and rDate+few days
            $retTs = $bTs + rand(2, 16) * 86400;
            $retTs = min($retTs, time()); // Cannot return in future
            $retAt = date('Y-m-d H:i:s', $retTs);
        } elseif ($status === 'borrowed') {
            // return_date must be >= today
            if (strtotime($rDate) < time()) {
                $status = 'overdue';
            } else {
                $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
            }
        } elseif ($status === 'overdue') {
             // return_date must be < today
             if (strtotime($rDate) >= time()) {
                 $rDate = date('Y-m-d', time() - rand(1, 5) * 86400);
                 $bDate = date('Y-m-d', strtotime($rDate) - 14 * 86400);
             }
             $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
        }

        $stmtBorrow->execute([$bId, $uId, $bDate, $rDate, $status, $retAt, date('Y-m-d H:i:s', $bTs)]);
    }
    echo "Created 50 Strict Borrow Records.\n";

    // 5. Reviews (20)
    $stmtReview = $pdo->prepare("INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, ?)");
    $comments = [
        "Sách viết rất lôi cuốn, mình đọc liền một mạch hết luôn.",
        "Kiến thức hữu ích cho việc làm tiểu luận, cảm ơn thư viện.",
        "Phần đầu hơi rườm rà nhưng càng về sau càng hay.",
        "Cuốn sách này là kinh điển rồi, không cần bàn cãi.",
        "Mình cực kỳ recommend cuốn này cho các bạn năm nhất nhé."
    ];
    
    // To ensure logical validity, we only allow reviews if the user has a 'returned' record
    $returnedRecords = $pdo->query("SELECT book_id, user_id, returned_at FROM borrow_records WHERE status = 'returned' ORDER BY RAND() LIMIT 20")->fetchAll();
    
    $reviewCount = 0;
    foreach ($returnedRecords as $rec) {
        $bId = $rec['book_id'];
        $uId = $rec['user_id'];
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM book_reviews WHERE book_id = ? AND user_id = ?");
        $check->execute([$bId, $uId]);
        if ($check->fetchColumn() > 0) continue;

        // Review created after returned_at
        $retTs = strtotime($rec['returned_at']);
        $revTs = $retTs + rand(3600, 86400 * 3); // 1 hour to 3 days after returning
        $revTs = min($revTs, time());

        $stmtReview->execute([$bId, $uId, rand(4, 5), $comments[array_rand($comments)], date('Y-m-d H:i:s', $revTs)]);
        $reviewCount++;
    }
    echo "Created $reviewCount Logically Valid Reviews.\n";

    // 6. Update Book quantities based on the new 'borrowed'/'overdue' records we just added
    echo "Updating Book quantities...\n";
    $stmtDecQty = $pdo->prepare("UPDATE books SET quantity = GREATEST(0, quantity - ?), status = CASE WHEN quantity - ? <= 0 THEN 'borrowed' ELSE status END WHERE book_id = ?");
    foreach ($borrowedCounts as $bId => $count) {
        $stmtDecQty->execute([$count, $count, $bId]);
    }

    echo "Batch 7: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
