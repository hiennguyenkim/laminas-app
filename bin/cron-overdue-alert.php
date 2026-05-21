<?php

declare(strict_types=1);

// Run with php bin/cron-overdue-alert.php

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

use Laminas\Db\Adapter\AdapterInterface;

try {
    // Load Laminas application container first before any output to prevent headers already sent warning
    $container = require 'config/container.php';
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting Overdue Alert Cron Job..." . PHP_EOL;
    
    /** @var AdapterInterface $db */
    $db = $container->get(AdapterInterface::class);

    // 1. Query all borrowed records that are overdue
    $sql = "SELECT br.*, b.title AS book_title 
            FROM borrow_records br
            JOIN books b ON br.book_id = b.book_id
            WHERE br.status = 'borrowed' AND br.return_date < CURDATE()";
    
    $records = iterator_to_array($db->query($sql)->execute());
    
    echo "Found " . count($records) . " overdue record(s) to process." . PHP_EOL;

    $updatedCount = 0;
    $notifiedCount = 0;

    foreach ($records as $row) {
        $recordId = (int)$row['borrow_id'];
        $userId = (int)$row['user_id'];
        $bookTitle = $row['book_title'];
        $returnDate = $row['return_date'];

        // 2. Update status of the borrow record to 'overdue'
        $updateSql = "UPDATE borrow_records SET status = 'overdue' WHERE borrow_id = ?";
        $db->query($updateSql, [$recordId]);
        $updatedCount++;

        // 3. Check if notification for this record already exists
        $checkNotifySql = "SELECT COUNT(*) AS cnt FROM notifications WHERE related_id = ? AND type = 'system'";
        $checkRes = $db->query($checkNotifySql, [$recordId])->current();
        $cnt = (int)($checkRes['cnt'] ?? 0);

        if ($cnt === 0) {
            // Insert alert notification for student
            $notifySql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at) 
                          VALUES (?, 0, ?, ?, 'system', ?, NOW())";
            
            $title = "Cảnh báo: Sách mượn quá hạn!";
            $message = sprintf(
                "Sách \"%s\" của bạn đã quá hạn trả vào ngày %s. Vui lòng hoàn trả sách về thư viện sớm nhất có thể.",
                $bookTitle,
                date('d/m/Y', strtotime($returnDate))
            );

            $db->query($notifySql, [$userId, $title, $message, $recordId]);
            $notifiedCount++;
            echo " - Processed Record #{$recordId}: Status set to 'overdue', notification sent to User #{$userId}" . PHP_EOL;
        } else {
            echo " - Processed Record #{$recordId}: Status set to 'overdue' (notification already exists)" . PHP_EOL;
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Overdue Alert Cron Job finished successfully. Updated: {$updatedCount}, Notifications created: {$notifiedCount}." . PHP_EOL;

} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
