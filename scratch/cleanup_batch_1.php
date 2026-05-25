<?php
/**
 * Update Script - Batch 1 Cleanup
 * Goal: Fix passwords to 'Admin@123' and make names/titles realistic for Batch 1.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 1 Cleanup: Updating passwords and making data realistic...\n";

    $newPasswordHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $firstNames = ['Lê', 'Hoàng', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô'];
    $middleNames = ['Thanh', 'Hữu', 'Kim', 'Trọng', 'Bảo', 'Minh', 'Ngọc', 'Xuân', 'Đình', 'Công'];
    $lastNames = ['Sơn', 'Hà', 'Tùng', 'Yến', 'Anh', 'Dương', 'Phúc', 'Linh', 'Quân', 'Thảo'];

    // 1. Update Admin (ID 1)
    $stmtUser = $pdo->prepare("UPDATE users SET password = ?, full_name = ?, nickname = ? WHERE user_id = 1");
    $stmtUser->execute([$newPasswordHash, 'Lê Quản Trị', 'Thủ Thư Trưởng']);
    echo "Updated Admin ID 1.\n";

    // 2. Update Students (IDs 2-11)
    $stmtStudent = $pdo->prepare("UPDATE users SET password = ?, full_name = ?, nickname = ?, date_of_birth = ?, phone = ? WHERE user_id = ?");
    for ($id = 2; $id <= 11; $id++) {
        $fullName = $firstNames[($id-2)%10] . ' ' . $middleNames[($id-2)%10] . ' ' . $lastNames[($id-2)%10];
        $nickname = "Nick" . ($id-1);
        $dob = rand(1998, 2004) . '-' . str_pad((string)rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)rand(1, 28), 2, '0', STR_PAD_LEFT);
        $phone = '03' . rand(10000000, 99999999);
        $stmtStudent->execute([$newPasswordHash, $fullName, $nickname, $dob, $phone, $id]);
    }
    echo "Updated 10 Students (IDs 2-11).\n";

    // 3. Update Books (IDs 1-100)
    $bookTemplates = [
        ['Lập trình C++ từ cơ bản đến nâng cao', 'Phạm Hữu Anh', 'Công nghệ thông tin'],
        ['Chiến tranh và Hòa bình', 'Leo Tolstoy', 'Văn học nước ngoài'],
        ['Bắt trẻ đồng xanh', 'J.D. Salinger', 'Văn học nước ngoài'],
        ['Tắt đèn', 'Ngô Tất Tố', 'Văn học Việt Nam'],
        ['Dế Mèn Phiêu Lưu Ký', 'Tô Hoài', 'Văn học Việt Nam'],
        ['Kinh tế học cho mọi người', 'Ha-Joon Chang', 'Kinh tế / Kinh doanh'],
        ['Vũ trụ trong vỏ hạt dẻ', 'Stephen Hawking', 'Khoa học'],
        ['Sức mạnh của thói quen', 'Charles Duhigg', 'Kỹ năng sống'],
        ['Giải thuật và Lập trình', 'Lê Minh Hoàng', 'Công nghệ thông tin'],
        ['Cơ sở văn hóa Việt Nam', 'Trần Ngọc Thêm', 'Lịch sử']
    ];

    $stmtBook = $pdo->prepare("UPDATE books SET title = ?, author = ?, category = ?, description = ? WHERE book_id = ?");
    for ($id = 1; $id <= 100; $id++) {
        $tpl = $bookTemplates[($id-1)%10];
        $title = $tpl[0] . " (Ấn bản " . (floor(($id-1)/10) + 1) . ")";
        $author = $tpl[1];
        $category = $tpl[2];
        $desc = "Mô tả thực tế cho cuốn $title. Đây là tài liệu quan trọng trong tủ sách $category.";
        $stmtBook->execute([$title, $author, $category, $desc, $id]);
    }
    echo "Updated 100 Books (IDs 1-100).\n";

    echo "Batch 1 Cleanup: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
