<?php
/**
 * Data Sanitization & Logical Audit Script
 * Goal: Fix inconsistencies in existing data (Batches 1-4)
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Starting Data Sanitization...\n";

    // 1. FIX BORROW RECORDS LOGIC
    echo "1. Fixing borrow records...\n";
    $records = $pdo->query("SELECT * FROM borrow_records")->fetchAll();
    $today = date('Y-m-d');
    
    $stmtUpdateBorrow = $pdo->prepare("UPDATE borrow_records SET borrow_date = ?, return_date = ?, status = ?, returned_at = ? WHERE borrow_id = ?");
    
    foreach ($records as $r) {
        $bDate = $r['borrow_date'];
        $rDate = $r['return_date'];
        $status = $r['status'];
        $retAt = $r['returned_at'];

        // Fix dates if they are in the future unexpectedly for past actions
        if ($bDate > $today) {
            $bDate = date('Y-m-d', strtotime('-' . rand(1, 10) . ' days'));
            $rDate = date('Y-m-d', strtotime($bDate . ' + 14 days'));
        }

        // Logic fixes based on Status
        if ($status === 'returned') {
            // Must have returned_at
            if (!$retAt) {
                // Generate a random return time between borrow_date and return_date (or slightly after)
                $maxReturnTs = strtotime($rDate . ' 23:59:59');
                $minReturnTs = strtotime($bDate . ' 08:00:00');
                // Ensure it's not in the future
                $maxReturnTs = min($maxReturnTs, time());
                
                $retTs = rand($minReturnTs, $maxReturnTs);
                $retAt = date('Y-m-d H:i:s', $retTs);
            }
        } elseif ($status === 'borrowed') {
            $retAt = null;
            // return_date must be >= today, else it should be overdue
            if ($rDate < $today) {
                // Adjust return_date to future so it remains "borrowed"
                $rDate = date('Y-m-d', strtotime('+' . rand(1, 10) . ' days'));
            }
        } elseif ($status === 'overdue') {
            $retAt = null;
            // return_date must be < today
            if ($rDate >= $today) {
                // Adjust return_date to past so it is truly "overdue"
                $rDate = date('Y-m-d', strtotime('-' . rand(1, 10) . ' days'));
                // Ensure borrow date is even older
                $bDate = date('Y-m-d', strtotime($rDate . ' - 14 days'));
            }
        }

        $stmtUpdateBorrow->execute([$bDate, $rDate, $status, $retAt, $r['borrow_id']]);
    }
    echo "   -> Borrow records logic synchronized.\n";

    // 2. FIX BOOK REVIEWS (Remove Batch 1 generic text)
    echo "2. Refining generic book reviews...\n";
    $realisticComments = [
        "Cuốn sách này thực sự mở rộng tầm nhìn của tôi. Cách hành văn rất lôi cuốn.",
        "Nội dung hơi học thuật nhưng đọc kỹ sẽ thấy rất nhiều giá trị thực tiễn.",
        "Rất phù hợp cho sinh viên năm nhất đang tìm hiểu về lĩnh vực này.",
        "Mình đã mượn cuốn này lần thứ hai rồi, mỗi lần đọc lại hiểu thêm một tầng ý nghĩa mới.",
        "Tuyệt vời! Kiến thức được trình bày logic và dễ hiểu.",
        "Sách hay, bìa đẹp, nội dung không có chỗ nào để chê.",
        "Một trong những cuốn sách đáng đọc nhất trong năm nay của mình.",
        "Hơi khó hiểu ở những chương đầu, nhưng càng về sau càng hấp dẫn."
    ];
    $reviews = $pdo->query("SELECT review_id FROM book_reviews WHERE comment LIKE '%Batch 1 Review%'")->fetchAll(PDO::FETCH_COLUMN);
    $stmtUpdateReview = $pdo->prepare("UPDATE book_reviews SET comment = ? WHERE review_id = ?");
    foreach ($reviews as $revId) {
        $stmtUpdateReview->execute([$realisticComments[array_rand($realisticComments)], $revId]);
    }
    echo "   -> Updated " . count($reviews) . " generic reviews to realistic text.\n";

    // 3. LINK BOOK IMPORTS TO ACTUAL BOOKS
    echo "3. Linking Book Imports...\n";
    $imports = $pdo->query("SELECT import_id, title FROM book_imports WHERE book_id IS NULL")->fetchAll();
    $stmtUpdateImport = $pdo->prepare("UPDATE book_imports SET book_id = ? WHERE import_id = ?");
    foreach ($imports as $imp) {
        // Find a matching book by title
        $stmtFindBook = $pdo->prepare("SELECT book_id FROM books WHERE title = ? LIMIT 1");
        $stmtFindBook->execute([$imp['title']]);
        $bId = $stmtFindBook->fetchColumn();
        
        if ($bId) {
            $stmtUpdateImport->execute([$bId, $imp['import_id']]);
        } else {
            // Just link to a random book to ensure data integrity
            $randomBookId = $pdo->query("SELECT book_id FROM books ORDER BY RAND() LIMIT 1")->fetchColumn();
            $stmtUpdateImport->execute([$randomBookId, $imp['import_id']]);
        }
    }
    echo "   -> Linked " . count($imports) . " book imports to existing books.\n";

    // 4. FIX BOOK STATUSES BASED ON QUANTITY
    echo "4. Synchronizing Book Statuses...\n";
    $pdo->query("UPDATE books SET status = 'available' WHERE quantity > 0");
    $pdo->query("UPDATE books SET status = 'borrowed' WHERE quantity <= 0");
    echo "   -> Book statuses synchronized with quantities.\n";

    echo "Data Sanitization COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
