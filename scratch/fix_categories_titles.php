<?php
/**
 * Ultimate Data Sanitization - Fix Category Encoding using Title Matching
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Fixing Categories based on book titles...\n";

    $books = $pdo->query("SELECT book_id, title FROM books")->fetchAll();
    $stmtUpdateBook = $pdo->prepare("UPDATE books SET category = ? WHERE book_id = ?");
    $stmtUpdateImport = $pdo->prepare("UPDATE book_imports SET category = ? WHERE title = ?");

    $count = 0;
    foreach ($books as $b) {
        $title = $b['title'];
        $cat = 'Khác';

        // Title rules from Batch 1-4
        if (strpos($title, 'Lập trình') !== false || strpos($title, 'Giải thuật') !== false || strpos($title, 'Python') !== false || strpos($title, 'Sáng tạo thuật toán') !== false) {
            $cat = 'Công nghệ thông tin';
        } elseif (strpos($title, 'Chiến tranh') !== false || strpos($title, 'Bắt trẻ') !== false || strpos($title, 'Suối Nguồn') !== false || strpos($title, 'Harry Potter') !== false || strpos($title, 'Hoàng tử bé') !== false || strpos($title, 'Nhà giả kim') !== false) {
            $cat = 'Văn học nước ngoài';
        } elseif (strpos($title, 'Tắt đèn') !== false || strpos($title, 'Dế Mèn') !== false || strpos($title, 'Số Đỏ') !== false || strpos($title, 'Mắt biếc') !== false || strpos($title, 'Cho tôi xin một vé') !== false) {
            $cat = 'Văn học Việt Nam';
        } elseif (strpos($title, 'Kinh tế') !== false || strpos($title, 'Cha Giàu') !== false || strpos($title, 'Bí mật tư duy') !== false || strpos($title, 'Dạy con làm giàu') !== false) {
            $cat = 'Kinh tế / Kinh doanh';
        } elseif (strpos($title, 'Vũ trụ') !== false || strpos($title, 'Lược sử Thời gian') !== false || strpos($title, 'Nguồn gốc các loài') !== false || strpos($title, 'Thế giới phẳng') !== false) {
            $cat = 'Khoa học';
        } elseif (strpos($title, 'Sức mạnh của thói quen') !== false || strpos($title, 'Đắc Nhân Tâm') !== false || strpos($title, 'Quẳng gánh lo đi') !== false) {
            $cat = 'Kỹ năng sống';
        } elseif (strpos($title, 'văn hóa Việt Nam') !== false || strpos($title, 'Lịch sử') !== false || strpos($title, 'Đại Việt sử ký') !== false) {
            $cat = 'Lịch sử';
        } elseif (strpos($title, 'Tâm lý học') !== false || strpos($title, 'Trí tuệ cảm xúc') !== false || strpos($title, 'Đọc vị') !== false) {
            $cat = 'Tâm lý / Sức khỏe';
        } elseif (strpos($title, 'Giải tích') !== false || strpos($title, 'Toán học') !== false) {
            $cat = 'Toán học';
        } elseif (strpos($title, 'Dinh dưỡng') !== false || strpos($title, 'sơ cứu') !== false || strpos($title, 'Yoga') !== false) {
            $cat = 'Sức khỏe';
        }

        $stmtUpdateBook->execute([$cat, $b['book_id']]);
        $stmtUpdateImport->execute([$cat, $title]);
        $count++;
    }

    echo "Fixed categories for $count books based on titles.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
