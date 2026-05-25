<?php
/**
 * Data Generation Script - Batch 4/10
 * Goal: 10 Students, 100 Books, REALISTIC Announcements, 20 Book Imports, 15 Public Chats.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 4: Starting insertion and refining system data...\n";

    // 1. Clear and Update Announcements to be REALISTIC
    $pdo->query("DELETE FROM announcements");
    $stmtAnn = $pdo->prepare("INSERT INTO announcements (title, content, type, start_date, end_date, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, 1, NOW())");
    
    $realisticAnns = [
        [
            'Thông báo: Lịch phục vụ bạn đọc dịp lễ Quốc khánh 02/09',
            'Thư viện HDPE xin thông báo lịch nghỉ lễ Quốc khánh như sau: Thư viện tạm ngưng phục vụ từ ngày 01/09 đến hết ngày 03/09. Hoạt động trở lại bình thường vào ngày 04/09. Chúc các bạn sinh viên có kỳ nghỉ lễ vui vẻ!',
            'holiday', '2024-08-25', '2024-09-03'
        ],
        [
            'Sách mới về tháng 5: Hơn 100 đầu sách Công nghệ và Kinh tế mới nhất',
            'Thư viện vừa nhập về kho hơn 100 đầu sách mới thuộc lĩnh vực AI, Blockchain và Quản trị kinh doanh hiện đại. Kính mời các bạn sinh viên đến tham khảo và mượn sách tại tầng 2.',
            'general', '2024-05-01', '2024-05-31'
        ],
        [
            'Cuộc thi: Review sách hay - Nhận ngay quà tặng từ Thư viện HDPE',
            'Nhằm khuyến khích phong trào đọc sách, Thư viện tổ chức cuộc thi viết cảm nhận về cuốn sách bạn yêu thích nhất. Giải thưởng là voucher mua sách và thẻ thành viên ưu tiên. Chi tiết xem tại Fanpage.',
            'contest', '2024-05-15', '2024-06-15'
        ],
        [
            'Thông báo: Bảo trì hệ thống tìm kiếm sách trực tuyến (OPAC)',
            'Để nâng cao chất lượng phục vụ, hệ thống tra cứu sách trực tuyến sẽ được bảo trì định kỳ vào khung giờ 23:00 - 02:00 ngày 25/05. Trong thời gian này, các tính năng tra cứu và gia hạn sách có thể bị gián đoạn.',
            'general', '2024-05-24', '2024-05-26'
        ],
        [
            'Sự kiện: Workshop Kỹ năng đọc nhanh và ghi nhớ hiệu quả',
            'Thư viện phối hợp cùng CLB Kỹ năng tổ chức buổi Workshop hướng dẫn các phương pháp đọc sách khoa học. Thời gian: 09:00 Thứ Bảy ngày 01/06. Địa điểm: Hội trường Lớn.',
            'event', '2024-05-20', '2024-06-01'
        ]
    ];

    foreach ($realisticAnns as $ann) {
        $stmtAnn->execute($ann);
    }
    echo "Refined 5 Realistic Announcements.\n";

    // 2. Students (31-40)
    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $firstNames = ['Đặng', 'Bùi', 'Võ', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Phan', 'Lương', 'Huỳnh'];
    $middleNames = ['Minh', 'Thanh', 'Văn', 'Thị', 'Đức', 'Trọng', 'Kim', 'Hoàng', 'Hữu', 'Bảo'];
    $lastNames = ['Tùng', 'Yến', 'Anh', 'Dương', 'Phúc', 'Linh', 'Quân', 'Thảo', 'Nam', 'Mai'];

    $studentIds = [];
    for ($i = 31; $i <= 40; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $middleNames[array_rand($middleNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $username = "student_$i";
        $email = "sv_$i@student.hdpe.edu.vn";
        $dob = rand(2000, 2005) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '09' . rand(10000000, 99999999);
        
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, nickname, date_of_birth, phone, created_at) VALUES (?, ?, ?, ?, 'student', ?, ?, ?, NOW())");
        $stmt->execute([$username, $email, $passwordHash, $fullName, "User$i", $dob, $phone]);
        $studentIds[] = $pdo->lastInsertId();
    }
    echo "Created 10 Students.\n";

    // 3. Books (301-400)
    $bookTemplates = [
        ['Python cho mọi người', 'Charles Severance', 'Công nghệ thông tin'],
        ['Harry Potter và Hòn đá Phù thủy', 'J.K. Rowling', 'Văn học nước ngoài'],
        ['Nguồn gốc các loài', 'Charles Darwin', 'Khoa học'],
        ['Bí mật tư duy triệu phú', 'T. Harv Eker', 'Kinh tế / Kinh doanh'],
        ['Mắt biếc', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam'],
        ['Quẳng gánh lo đi và vui sống', 'Dale Carnegie', 'Kỹ năng sống'],
        ['Lược sử loài người', 'Yuval Noah Harari', 'Lịch sử'],
        ['Giải tích 1', 'Nhiều tác giả', 'Toán học'],
        ['Cẩm nang sơ cứu', 'NXB Y Học', 'Sức khỏe'],
        ['Trí tuệ cảm xúc', 'Daniel Goleman', 'Tâm lý / Sức khỏe']
    ];

    $bookIds = [];
    for ($i = 301; $i <= 400; $i++) {
        $tpl = $bookTemplates[($i-301)%10];
        $title = $tpl[0] . " (Vol " . ceil(($i-300)/10) . ")";
        $author = $tpl[1];
        $cat = $tpl[2];
        
        $stmt = $pdo->prepare("INSERT INTO books (title, author, isbn, category, description, publisher, published_year, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, 'NXB Tri Thức', ?, ?, 'available', NOW())");
        $stmt->execute([
            $title, $author, "ISBN-B4-" . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
            $cat, "Dữ liệu thực tế cho cuốn $title. Đây là đầu sách bán chạy trong danh mục $cat.",
            rand(2010, 2023), rand(5, 15)
        ]);
        $bookIds[] = $pdo->lastInsertId();
    }
    echo "Created 100 Books.\n";

    // 4. Book Imports (20)
    $stmtImport = $pdo->prepare("INSERT INTO book_imports (invoice_code, title, author, isbn, category, publisher, published_year, quantity, price, imported_by, import_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'approved', NOW())");
    for ($i = 1; $i <= 20; $i++) {
        $bIdx = array_rand($bookTemplates);
        $title = $bookTemplates[$bIdx][0];
        $author = $bookTemplates[$bIdx][1];
        $cat = $bookTemplates[$bIdx][2];
        $invoice = "HD-" . date('Ymd') . "-" . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $price = rand(50000, 250000);
        $qty = rand(10, 50);
        $date = date('Y-m-d', strtotime('-' . rand(0, 10) . ' days'));
        
        $stmtImport->execute([
            $invoice, $title, $author, "ISBN-IMP-" . rand(10000, 99999),
            $cat, "NXB Trẻ", rand(2020, 2024), $qty, $price, $date
        ]);
    }
    echo "Created 20 Book Import Records.\n";

    // 5. Public Chats (15)
    $allUserIds = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
    $messages = [
        "Chào mọi người, có ai biết cuốn Harry Potter còn bản nào không?",
        "Thư viện hôm nay đông quá, mãi mới tìm được chỗ ngồi.",
        "Mọi người ơi, workshop kỹ năng đọc nhanh hay lắm nè, nên đi nhé!",
        "Ai có tài liệu về Giải tích 1 không cho mình mượn với.",
        "Sách mới về toàn cực phẩm thôi, nhanh tay mượn nhé các bạn.",
        "Admin ơi, cho em hỏi lịch nghỉ lễ thư viện có mở cửa không ạ?",
        "Cái OPAC bảo trì rồi, không tra được sách online.",
        "Review cuốn Nhà giả kim: Rất đáng đọc, thay đổi tư duy luôn.",
        "Mọi người có ai mượn cuốn Đắc Nhân Tâm chưa ạ?",
        "Thư viện máy lạnh mát quá, học bài tập trung hẳn."
    ];
    $stmtChat = $pdo->prepare("INSERT INTO public_chats (user_id, message, created_at) VALUES (?, ?, NOW())");
    for ($i = 0; $i < 15; $i++) {
        $uId = $allUserIds[array_rand($allUserIds)];
        $stmtChat->execute([$uId, $messages[array_rand($messages)]]);
    }
    echo "Created 15 Public Chat messages.\n";

    echo "Batch 4: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
