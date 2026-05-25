<?php
/**
 * Relational Integrity & Deep Logic Audit Script
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "--- RELATIONAL INTEGRITY AUDIT ---\n";

    // 1. Check if all book categories exist in book_categories table
    $sqlCat = "
        SELECT DISTINCT b.category 
        FROM books b 
        LEFT JOIN book_categories bc ON b.category = bc.name 
        WHERE bc.name IS NULL AND b.category IS NOT NULL
    ";
    $invalidCats = $pdo->query($sqlCat)->fetchAll(PDO::FETCH_COLUMN);
    echo "1. Books with invalid categories: " . count($invalidCats) . "\n";
    if (count($invalidCats) > 0) {
        foreach ($invalidCats as $ic) echo "   - Missing in book_categories: '$ic'\n";
    }

    // 2. Check Ghost Borrow Records (book_id or user_id doesn't exist)
    $sqlGhostBorrow = "
        SELECT COUNT(*) FROM borrow_records br
        LEFT JOIN books b ON br.book_id = b.book_id
        LEFT JOIN users u ON br.user_id = u.user_id
        WHERE b.book_id IS NULL OR u.user_id IS NULL
    ";
    $ghostBorrows = $pdo->query($sqlGhostBorrow)->fetchColumn();
    echo "2. Ghost borrow records: " . $ghostBorrows . "\n";

    // 3. Check Ghost Reviews
    $sqlGhostReview = "
        SELECT COUNT(*) FROM book_reviews br
        LEFT JOIN books b ON br.book_id = b.book_id
        LEFT JOIN users u ON br.user_id = u.user_id
        WHERE b.book_id IS NULL OR u.user_id IS NULL
    ";
    $ghostReviews = $pdo->query($sqlGhostReview)->fetchColumn();
    echo "3. Ghost book reviews: " . $ghostReviews . "\n";

    // 4. Check Ghost Imports
    $sqlGhostImport = "
        SELECT COUNT(*) FROM book_imports bi
        LEFT JOIN books b ON bi.book_id = b.book_id
        WHERE bi.book_id IS NOT NULL AND b.book_id IS NULL
    ";
    $ghostImports = $pdo->query($sqlGhostImport)->fetchColumn();
    echo "4. Ghost book imports (pointing to missing book): " . $ghostImports . "\n";

    // 5. Check if Import categories match
    $sqlImportCat = "
        SELECT DISTINCT bi.category 
        FROM book_imports bi 
        LEFT JOIN book_categories bc ON bi.category = bc.name 
        WHERE bc.name IS NULL AND bi.category IS NOT NULL
    ";
    $invalidImportCats = $pdo->query($sqlImportCat)->fetchAll(PDO::FETCH_COLUMN);
    echo "5. Imports with invalid categories: " . count($invalidImportCats) . "\n";
    if (count($invalidImportCats) > 0) {
        foreach ($invalidImportCats as $ic) echo "   - Missing in book_categories: '$ic'\n";
    }

    // 6. Notifications check
    $sqlGhostNoti = "
        SELECT COUNT(*) FROM notifications n
        LEFT JOIN users u ON n.user_id = u.user_id
        WHERE n.user_id IS NOT NULL AND u.user_id IS NULL
    ";
    $ghostNoti = $pdo->query($sqlGhostNoti)->fetchColumn();
    echo "6. Notifications for missing users: " . $ghostNoti . "\n";

    echo "--- AUDIT COMPLETE ---\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
