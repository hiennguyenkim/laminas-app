<?php
/**
 * Data Generation Script - Batch 5/10 (Focus: Realistic Book Imports)
 * Goal: Generate 50 realistic book import records spanning different months, statuses, and types.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 5: Enriching Book Import Data...\n";

    // Fetch existing books to link some imports
    $books = $pdo->query("SELECT book_id, title, author, category, publisher, published_year FROM books")->fetchAll();
    if (empty($books)) {
        die("ERROR: No books found. Run previous batches first.\n");
    }

    $stmtImport = $pdo->prepare("INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, price, note, imported_by, status, import_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");

    $importTypes = ['purchase', 'purchase', 'purchase', 'donation', 'other'];
    $statuses = ['approved', 'approved', 'approved', 'approved', 'approved', 'pending', 'pending', 'rejected'];
    $notesApproved = ["Nhập kho thành công", "Sách về đủ số lượng, tình trạng tốt", "Mua bổ sung đợt 2", "Sách tài trợ từ cựu sinh viên", ""];
    $notesPending = ["Chờ thủ thư trưởng duyệt", "Đang kiểm kê lại tình trạng sách", "Thiếu hóa đơn VAT, cần bổ sung", ""];
    $notesRejected = ["Sách bị rách bìa, đã yêu cầu đổi trả", "Sai thông tin ISBN so với thực tế", "Hủy đơn nhập do vượt quá ngân sách"];

    $count = 0;
    for ($i = 1; $i <= 50; $i++) {
        $book = $books[array_rand($books)]; // Link to an existing book (most common scenario for expanding a library)
        
        // Sometimes create a "new" unlinked import (simulate importing a book not yet in catalog or just manual entry)
        $isLinked = rand(1, 100) > 20; // 80% chance linked to existing book
        $bId = $isLinked ? $book['book_id'] : null;
        $title = $isLinked ? $book['title'] : "Sách mới " . uniqid();
        $author = $isLinked ? $book['author'] : "Tác giả mới";
        $category = $isLinked ? $book['category'] : "Khác";
        $publisher = $isLinked ? $book['publisher'] : "NXB Tri Thức";
        $pubYear = $isLinked ? $book['published_year'] : rand(2020, 2024);

        $type = $importTypes[array_rand($importTypes)];
        $status = $statuses[array_rand($statuses)];
        
        $qty = ($type === 'donation') ? rand(1, 5) : rand(10, 100);
        $price = ($type === 'donation') ? 0 : (rand(50, 350) * 1000); // 50k - 350k

        if ($status === 'approved') {
            $note = $notesApproved[array_rand($notesApproved)];
        } elseif ($status === 'pending') {
            $note = $notesPending[array_rand($notesPending)];
        } else {
            $note = $notesRejected[array_rand($notesRejected)];
        }

        // Generate realistic dates spread over the last 6 months
        $daysAgo = rand(1, 180);
        $importDate = date('Y-m-d', strtotime("-$daysAgo days"));
        $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days + " . rand(1, 10) . " hours"));
        
        $invoiceCode = "HD-" . date('Ym', strtotime($importDate)) . "-" . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        $isbn = "ISBN-IMP-" . rand(100000, 999999);

        $stmtImport->execute([
            $bId, $invoiceCode, $title, $author, $isbn, $category, $publisher, $pubYear, $qty, $type, $price, $note, $status, $importDate, $createdAt
        ]);
        $count++;
    }

    echo "Created $count realistic Book Import Records (Mixed statuses, types, dates).\n";
    echo "Batch 5: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
