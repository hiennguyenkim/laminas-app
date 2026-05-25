<?php
/**
 * Data Generation Script - Final Batches (9 & 10)
 * Goal: Reach ~100 students, ~1000 books. Populate ALL tables realistically.
 * Simulate an ACTIVELY RUNNING system.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Final Phase: Injecting realistic active system data...\n";

    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $adminId = 1;

    // --- 1. STUDENTS (Target: ~50 more) ---
    $numStudents = rand(45, 55);
    $firstNames = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Huỳnh', 'Hoàng', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý'];
    $middleNames = ['Văn', 'Thị', 'Minh', 'Thanh', 'Hữu', 'Đức', 'Ngọc', 'Quang', 'Bảo', 'Gia', 'Hoài', 'Xuân', 'Kim', 'Thúy', 'Thu'];
    $lastNames = ['Hùng', 'Hương', 'Linh', 'Dũng', 'Nam', 'Thắng', 'Lan', 'Hoa', 'Phúc', 'Tâm', 'Yến', 'Trang', 'Khánh', 'Sơn', 'Tùng'];
    $nickSuffixes = ['VIP', 'Z', '99', '00', 'Bé', 'Múp', 'Trầm', 'Lì', 'Chan'];

    $newStudentIds = [];
    $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, date_of_birth, phone, created_at) VALUES (?, ?, ?, ?, 'student', ?, ?, ?, ?)");
    
    for ($i = 0; $i < $numStudents; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $middleNames[array_rand($middleNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $uidStr = uniqid();
        $username = "hv_" . substr($uidStr, -6);
        $email = $username . "@student.hdpe.edu.vn";
        $dob = rand(1999, 2005) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '0' . rand(3,9) . rand(10000000, 99999999);
        
        $nickname = null;
        if (rand(1, 100) > 35) { // 65% chance
            $fName = explode(' ', $fullName)[2] ?? 'User';
            $nickname = $fName . ' ' . $nickSuffixes[array_rand($nickSuffixes)];
        }

        $createdAt = date('Y-m-d H:i:s', time() - rand(0, 180 * 86400)); // Joined sometime in the last 6 months
        $stmtUser->execute([$username, $email, $passwordHash, $fullName, $nickname, $dob, $phone, $createdAt]);
        $newStudentIds[] = $pdo->lastInsertId();
    }
    echo "Created $numStudents Students.\n";

    // --- 2. BOOKS (Target: ~500 more) ---
    $numBooks = rand(480, 520);
    $categories = $pdo->query("SELECT name FROM book_categories")->fetchAll(PDO::FETCH_COLUMN);
    $adjectives = ['Cơ bản', 'Nâng cao', 'Toàn tập', 'Hiện đại', 'Thực hành', 'Ứng dụng', 'Chuyên sâu', 'Tập 1', 'Tập 2', 'Bản đặc biệt'];
    $topics = ['Lập trình', 'Lịch sử', 'Kinh tế', 'Toán học', 'Văn học', 'Tâm lý', 'Khoa học', 'Sức khỏe', 'Nghệ thuật', 'Triết học'];
    $authors = ['Nguyễn Nhật Ánh', 'Dale Carnegie', 'Stephen Hawking', 'Haruki Murakami', 'Nam Cao', 'Vũ Trọng Phụng', 'Dan Brown', 'Paulo Coelho', 'Nhiều tác giả', 'NXB Giáo Dục'];

    $newBookIds = [];
    $stmtBook = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, 'NXB Tri Thức', ?, ?, 'available', ?)");
    
    for ($i = 0; $i < $numBooks; $i++) {
        $topic = $topics[array_rand($topics)];
        $adj = $adjectives[array_rand($adjectives)];
        $title = "$topic $adj - " . uniqid();
        $author = $authors[array_rand($authors)];
        $cat = $categories[array_rand($categories)]; // Map to a random valid category
        $qty = rand(1, 20);
        
        $createdAt = date('Y-m-d H:i:s', time() - rand(0, 120 * 86400)); // Added in last 4 months
        
        $stmtBook->execute([
            $title, $author, "ISBN-F-" . rand(1000, 9999) . "-" . rand(100, 999),
            $cat, "Tài liệu quan trọng thuộc danh mục $cat. Xuất bản năm " . rand(2015, 2024),
            rand(2010, 2024), $qty, $createdAt
        ]);
        $newBookIds[] = $pdo->lastInsertId();
    }
    echo "Created $numBooks Books.\n";

    // --- 3. BOOK IMPORTS (Active System) ---
    $numImports = rand(40, 60);
    $stmtImport = $pdo->prepare("INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, price, note, imported_by, status, import_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
    
    for ($i = 0; $i < $numImports; $i++) {
        $bId = $newBookIds[array_rand($newBookIds)];
        $bData = $pdo->query("SELECT title, author, category FROM books WHERE book_id = $bId")->fetch();

        $type = rand(0,1) ? 'purchase' : 'donation';
        $status = ['approved', 'approved', 'pending', 'rejected'][rand(0,3)];
        $price = $type === 'donation' ? 0 : rand(50, 500) * 1000;
        $qty = rand(5, 50);
        $daysAgo = rand(0, 60);
        
        $stmtImport->execute([
            $bId, "HD-F-" . date('Ymd') . "-" . rand(1000, 9999), $bData['title'], $bData['author'], "ISBN-IMP-F-" . rand(100, 999),
            $bData['category'], "NXB Tổng Hợp", rand(2020, 2024), $qty, $type, $price, "Nhập kho đợt cuối", $status, 
            date('Y-m-d', time() - $daysAgo * 86400), date('Y-m-d H:i:s', time() - $daysAgo * 86400)
        ]);
    }
    echo "Created $numImports Book Imports.\n";

    // --- 4. BORROW RECORDS (Strict Hard Reservation & Active System) ---
    $numBorrows = rand(150, 250);
    $allStudents = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
    $allBooks = $pdo->query("SELECT book_id FROM books")->fetchAll(PDO::FETCH_COLUMN);

    $stmtBorrow = $pdo->prepare("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, returned_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $borrowedCounts = [];

    // Track active borrows per user to enforce limit (5)
    $userActiveBorrows = [];
    foreach($allStudents as $u) $userActiveBorrows[$u] = 0;
    
    // Initialize current active counts from DB
    $currentBorrows = $pdo->query("SELECT user_id, COUNT(*) as cnt FROM borrow_records WHERE status IN ('borrowed', 'overdue') GROUP BY user_id")->fetchAll();
    foreach($currentBorrows as $cb) {
        $userActiveBorrows[$cb['user_id']] = (int)$cb['cnt'];
    }

    $actualBorrowsCreated = 0;
    for ($i = 0; $i < $numBorrows; $i++) {
        $uId = $allStudents[array_rand($allStudents)];
        $bId = $allBooks[array_rand($allBooks)];
        
        $statuses = ['borrowed', 'returned', 'returned', 'overdue', 'pending', 'pending'];
        $status = $statuses[array_rand($statuses)];

        // Enforce Borrow Limit (5)
        if (in_array($status, ['borrowed', 'overdue'])) {
            if ($userActiveBorrows[$uId] >= 5) {
                $status = 'returned'; // Force it to be a past completed record if limit reached
            } else {
                $userActiveBorrows[$uId]++;
            }
        }

        $bTs = time() - rand(1, 90) * 86400; // up to 90 days ago
        
        if ($status === 'pending') {
            // Pending should be very recent (last 24-48 hours usually, let's say last 2 days)
            $bTs = time() - rand(0, 2 * 86400);
        }

        $bDate = date('Y-m-d', $bTs);
        $rDate = date('Y-m-d', $bTs + 14 * 86400); 
        $retAt = null;

        if ($status === 'returned') {
            $retTs = $bTs + rand(1, 14) * 86400 + rand(0, 86400); 
            $retTs = min($retTs, time()); 
            $retAt = date('Y-m-d H:i:s', $retTs);
        } elseif ($status === 'borrowed') {
            if (strtotime($rDate) < time()) $status = 'overdue';
            else $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
        } elseif ($status === 'overdue') {
             if (strtotime($rDate) >= time()) {
                 $rDate = date('Y-m-d', time() - rand(1, 20) * 86400);
                 $bDate = date('Y-m-d', strtotime($rDate) - 14 * 86400);
             }
             $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
        } elseif ($status === 'pending') {
             // Pending ALSO deducts quantity in Hard Reservation
             $borrowedCounts[$bId] = ($borrowedCounts[$bId] ?? 0) + 1;
        }

        $createdAt = date('Y-m-d H:i:s', $bTs - rand(0, 3600)); 
        $stmtBorrow->execute([$bId, $uId, $bDate, $rDate, $status, $retAt, $createdAt]);
        $actualBorrowsCreated++;
    }
    
    // Update Book quantities
    $stmtDecQty = $pdo->prepare("UPDATE books SET quantity = GREATEST(0, quantity - ?), status = CASE WHEN quantity - ? <= 0 THEN 'borrowed' ELSE status END WHERE book_id = ?");
    foreach ($borrowedCounts as $bId => $count) {
        $stmtDecQty->execute([$count, $count, $bId]);
    }
    echo "Created $actualBorrowsCreated Borrow Records & Updated Quantities.\n";

    // --- 5. REVIEWS ---
    $numReviews = rand(50, 100);
    $stmtReview = $pdo->prepare("INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, ?)");
    $comments = ["Sách rất hay!", "Cần thiết cho môn học", "Khá nhàm chán", "Đọc giải trí tốt", "Kiến thức hàn lâm", "Tuyệt vời", "10 điểm không có nhưng"];
    
    $returnedRecords = $pdo->query("SELECT book_id, user_id, returned_at FROM borrow_records WHERE status = 'returned' ORDER BY RAND() LIMIT $numReviews")->fetchAll();
    
    $reviewCount = 0;
    foreach ($returnedRecords as $rec) {
        $bId = $rec['book_id']; $uId = $rec['user_id'];
        $check = $pdo->query("SELECT COUNT(*) FROM book_reviews WHERE book_id = $bId AND user_id = $uId")->fetchColumn();
        if ($check > 0) continue;

        $retTs = strtotime($rec['returned_at']);
        $revTs = min($retTs + rand(60, 86400 * 2), time());

        $stmtReview->execute([$bId, $uId, rand(3, 5), $comments[array_rand($comments)], date('Y-m-d H:i:s', $revTs)]);
        $reviewCount++;
    }
    echo "Created $reviewCount Reviews.\n";

    // --- 6. SUPPORT TICKETS & MESSAGES ---
    $numTickets = rand(20, 30);
    $stmtTicket = $pdo->prepare("INSERT INTO support_tickets (user_id, category, title, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtMsg = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, ?, ?, ?)");
    
    for ($i = 0; $i < $numTickets; $i++) {
        $uId = $allStudents[array_rand($allStudents)];
        $cat = ['lost_item', 'damaged_book', 'card_issue', 'other'][rand(0,3)];
        $startTs = time() - rand(1, 14) * 86400; // Last 2 weeks
        
        $status = ['open', 'in_progress', 'closed'][rand(0,2)];
        $updatedTs = $startTs + rand(3600, 86400*2);
        
        $stmtTicket->execute([$uId, $cat, "Vấn đề $cat - " . uniqid(), "Sinh viên cần hỗ trợ về $cat.", $status, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $updatedTs)]);
        $tId = $pdo->lastInsertId();

        $stmtMsg->execute([$tId, $uId, 'user', "Sinh viên cần hỗ trợ về $cat.", date('Y-m-d H:i:s', $startTs)]);
        
        if ($status !== 'open') {
            $stmtMsg->execute([$tId, $adminId, 'admin', "Thủ thư đã tiếp nhận yêu cầu.", date('Y-m-d H:i:s', $startTs + 3600)]);
            if ($status === 'open') { // Simulate user replying back making it open again
                 $stmtMsg->execute([$tId, $uId, 'user', "Dạ em cảm ơn.", date('Y-m-d H:i:s', $updatedTs)]);
            }
        }
    }
    echo "Created $numTickets Tickets & Threads.\n";

    // --- 7. PUBLIC CHATS ---
    $numChats = rand(30, 50);
    $stmtChat = $pdo->prepare("INSERT INTO public_chats (user_id, message, created_at) VALUES (?, ?, ?)");
    for ($i = 0; $i < $numChats; $i++) {
        $uId = $allStudents[array_rand($allStudents)];
        $chatTs = time() - rand(0, 7 * 86400); // Last 7 days to look active
        $stmtChat->execute([$uId, "Chat message " . uniqid() . " đang hoạt động.", date('Y-m-d H:i:s', $chatTs)]);
    }
    echo "Created $numChats Chat Messages.\n";

    // --- 8. ANNOUNCEMENTS ---
    $stmtAnn = $pdo->prepare("INSERT INTO announcements (title, content, type, start_date, end_date, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, 1, ?)");
    $annTs = time() - rand(1, 5) * 86400;
    $stmtAnn->execute([
        "Cập nhật nội quy mượn trả sách (Mới)",
        "Thư viện xin thông báo về việc áp dụng quy định giữ chỗ sách (Hard Reservation). Sinh viên đặt sách vui lòng đến nhận trong vòng 24h.",
        "general", date('Y-m-d', $annTs), null, date('Y-m-d H:i:s', $annTs)
    ]);
    echo "Created 1 Active Announcement.\n";

    echo "--- FINAL PHASE COMPLETED SUCCESSFULY ---\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
