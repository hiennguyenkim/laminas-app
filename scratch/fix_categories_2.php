<?php
$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $corruptedCats = $pdo->query("SELECT DISTINCT category FROM books")->fetchAll(PDO::FETCH_COLUMN);
    $stmtUpdateBook = $pdo->prepare("UPDATE books SET category = ? WHERE category = ?");
    $stmtUpdateImport = $pdo->prepare("UPDATE book_imports SET category = ? WHERE category = ?");

    $count = 0;
    foreach ($corruptedCats as $c) {
        $correct = null;
        if (strpos($c, 'thĂ´ng tin') !== false) $correct = 'Công nghệ thông tin';
        elseif (strpos($c, 'ngoĂ i') !== false) $correct = 'Văn học nước ngoài';
        elseif (strpos($c, 'Vi?t Nam') !== false) $correct = 'Văn học Việt Nam';
        elseif (strpos($c, 'Kinh doanh') !== false) $correct = 'Kinh tế / Kinh doanh';
        elseif (strpos($c, 'Khoa h?c') !== false) $correct = 'Khoa học';
        elseif (strpos($c, 'n?ng s?ng') !== false) $correct = 'Kỹ năng sống';
        elseif (strpos($c, 'L?ch s?') !== false) $correct = 'Lịch sử';
        elseif (strpos($c, 'TĂ¢m lÆ°') !== false) $correct = 'Tâm lý / Sức khỏe';
        elseif (strpos($c, 'ToĂ¡n h?c') !== false) $correct = 'Toán học';
        elseif (strpos($c, 'S?c kh?e') !== false) $correct = 'Sức khỏe';
        elseif (strpos($c, 'Ngo?i ng?') !== false) $correct = 'Ngoại ngữ';
        elseif (strpos($c, 'thu?t') !== false) $correct = 'Công nghệ';
        elseif (strpos($c, 'KhĂ¡c') !== false) $correct = 'Khác';

        if ($correct) {
            $stmtUpdateBook->execute([$correct, $c]);
            $stmtUpdateImport->execute([$correct, $c]);
            $count++;
            echo "Mapped: " . $c . " -> " . $correct . "\n";
        }
    }
    echo "Fixed $count categories.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
