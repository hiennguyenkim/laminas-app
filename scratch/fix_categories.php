<?php
/**
 * Data Sanitization - Fix Category Encoding Issues
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Fixing Category Encodings...\n";

    // 1. Reset book_categories table
    $pdo->query("TRUNCATE TABLE book_categories");
    
    $correctCategories = [
        'Công nghệ thông tin',
        'Văn học nước ngoài',
        'Kỹ năng học tập',
        'Tâm lý / Sức khỏe',
        'Kinh tế / Kinh doanh',
        'Khoa học',
        'Kỹ năng sống',
        'Văn học Việt Nam',
        'Triết học',
        'Tiểu thuyết',
        'Thiếu nhi',
        'Sức khỏe',
        'Lịch sử',
        'Tôn giáo / Tâm linh',
        'Ngoại ngữ',
        'Y học',
        'Xã hội học',
        'Công nghệ',
        'Ẩm thực',
        'Toán học',
        'Địa lý',
        'Khác'
    ];

    $stmtInsertCat = $pdo->prepare("INSERT INTO book_categories (name) VALUES (?)");
    foreach ($correctCategories as $cat) {
        $stmtInsertCat->execute([$cat]);
    }
    echo "1. Reset book_categories table with correct UTF-8 text.\n";

    // 2. Fix categories in books table
    // We fetch all distinct categories and map them
    $corruptedCats = $pdo->query("SELECT DISTINCT category FROM books")->fetchAll(PDO::FETCH_COLUMN);
    
    $mapping = [];
    foreach ($corruptedCats as $c) {
        $cLow = strtolower($c);
        if (str_contains($cLow, 'ngh? thĂ´ng tin')) $mapping[$c] = 'Công nghệ thông tin';
        elseif (str_contains($cLow, 'n??c ngoĂ i')) $mapping[$c] = 'Văn học nước ngoài';
        elseif (str_contains($cLow, 'vi?t nam')) $mapping[$c] = 'Văn học Việt Nam';
        elseif (str_contains($cLow, 'kinh doanh')) $mapping[$c] = 'Kinh tế / Kinh doanh';
        elseif (str_contains($cLow, 'khoa h?c')) $mapping[$c] = 'Khoa học';
        elseif (str_contains($cLow, 'n?ng s?ng')) $mapping[$c] = 'Kỹ năng sống';
        elseif (str_contains($cLow, 'l?ch s?')) $mapping[$c] = 'Lịch sử';
        elseif (str_contains($cLow, 'tĂ¢m lÆ°')) $mapping[$c] = 'Tâm lý / Sức khỏe';
        elseif (str_contains($cLow, 'toĂ¡n h?c')) $mapping[$c] = 'Toán học';
        elseif (str_contains($cLow, 's?c kh?e')) $mapping[$c] = 'Sức khỏe';
        elseif (str_contains($cLow, 'ngo?i ng?')) $mapping[$c] = 'Ngoại ngữ';
        elseif (str_contains($cLow, 'k? thu?t')) $mapping[$c] = 'Công nghệ'; // Re-map 'Kỹ thuật' to 'Công nghệ'
    }

    $stmtUpdateBook = $pdo->prepare("UPDATE books SET category = ? WHERE category = ?");
    $stmtUpdateImport = $pdo->prepare("UPDATE book_imports SET category = ? WHERE category = ?");
    
    $count = 0;
    foreach ($mapping as $corrupted => $correct) {
        $stmtUpdateBook->execute([$correct, $corrupted]);
        $stmtUpdateImport->execute([$correct, $corrupted]);
        $count++;
    }

    echo "2. Mapped and fixed $count corrupted categories in books & book_imports tables.\n";
    echo "Category Encoding Fix COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
