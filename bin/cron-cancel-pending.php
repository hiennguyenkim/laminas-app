<?php

declare(strict_types=1);

// Run with: php bin/cron-cancel-pending.php

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

use Laminas\Db\Adapter\AdapterInterface;
use Library\Service\CirculationService;
use Library\Model\Table\BookTable;

try {
    // Load Laminas application container
    $container = require 'config/container.php';
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting Auto-Cancel Pending Requests Cron Job..." . PHP_EOL;
    
    /** @var AdapterInterface $db */
    $db = $container->get(AdapterInterface::class);
    
    /** @var CirculationService $circulationService */
    $circulationService = $container->get(CirculationService::class);
    
    /** @var BookTable $bookTable */
    $bookTable = $container->get(BookTable::class);

    // 1. Tìm các phiếu chờ duyệt (pending) đã được tạo quá 24 giờ
    $sql = "SELECT br.borrow_id, br.user_id, b.title AS book_title 
            FROM borrow_records br
            JOIN books b ON br.book_id = b.book_id
            WHERE br.status = 'pending' AND br.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    
    $records = iterator_to_array($db->query($sql)->execute());
    
    echo "Found " . count($records) . " expired pending request(s) to cancel." . PHP_EOL;

    $cancelledCount = 0;

    foreach ($records as $row) {
        $recordId = (int)$row['borrow_id'];
        $userId = (int)$row['user_id'];
        $bookTitle = $row['book_title'];

        // 2. Reject the borrow request using CirculationService
        // This will delete the record and increment the book availability (Hard Reservation rollback)
        $circulationService->rejectBorrow($recordId);
        $cancelledCount++;

        // 3. Send notification to the student
        $notifySql = "INSERT INTO notifications (user_id, sender_id, title, message, type, created_at) 
                      VALUES (?, 0, ?, ?, 'system', NOW())";
        
        $title = "Hủy yêu cầu mượn sách tự động";
        $message = sprintf(
            "Yêu cầu mượn cuốn sách \"%s\" của bạn đã bị hủy tự động do quá 24h không xác nhận tại quầy. Hệ thống đã hoàn trả sách về kho.",
            $bookTitle
        );

        $db->query($notifySql, [$userId, $title, $message]);
        echo " - Cancelled Record #{$recordId}: Book quantity restored, notification sent to User #{$userId}" . PHP_EOL;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Auto-Cancel Cron Job finished successfully. Cancelled requests: {$cancelledCount}." . PHP_EOL;

    // 5. Tự động từ chối yêu cầu gia hạn quá 48h (Hạng mục 4)
    echo "[" . date('Y-m-d H:i:s') . "] Starting Auto-Reject Expired Renewal Requests..." . PHP_EOL;
    $renewSql = "SELECT br.borrow_id, br.user_id, b.title AS book_title 
                 FROM borrow_records br
                 JOIN books b ON br.book_id = b.book_id
                 WHERE br.is_renew_pending = 1 AND br.updated_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)";
    
    $renewRecords = iterator_to_array($db->query($renewSql)->execute());
    echo "Found " . count($renewRecords) . " expired renewal request(s) to reject." . PHP_EOL;

    $rejectedRenewCount = 0;
    foreach ($renewRecords as $row) {
        $recordId = (int)$row['borrow_id'];
        $userId = (int)$row['user_id'];
        $bookTitle = $row['book_title'];

        $circulationService->rejectRenew($recordId);
        $rejectedRenewCount++;
        echo " - Rejected Renewal #{$recordId}: Student notified for book \"{$bookTitle}\"." . PHP_EOL;
    }
    echo "Auto-Reject Renewals finished. Rejected: {$rejectedRenewCount}." . PHP_EOL;

    // 4. Dọn dẹp thông báo cũ (Hạng mục 1)
    echo "[" . date('Y-m-d H:i:s') . "] Starting Notification Cleanup..." . PHP_EOL;
    $cleanupSql = "DELETE FROM notifications 
                   WHERE (is_read = 1 OR is_deleted = 1) 
                   AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $cleanupResult = $db->query($cleanupSql)->execute();
    echo "Deleted " . $cleanupResult->getAffectedRows() . " old read/deleted notification(s)." . PHP_EOL;

} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
