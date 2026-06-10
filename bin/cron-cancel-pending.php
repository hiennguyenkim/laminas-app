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

    // 5. Tự động từ chối yêu cầu gia hạn quá 24h (Hạng mục 4)
    echo "[" . date('Y-m-d H:i:s') . "] Starting Auto-Reject Expired Renewal Requests..." . PHP_EOL;
    $renewSql = "SELECT br.borrow_id, br.user_id, b.title AS book_title 
                 FROM borrow_records br
                 JOIN books b ON br.book_id = b.book_id
                 WHERE br.is_renew_pending = 1 
                   AND COALESCE(
                       (SELECT MIN(n.created_at) FROM notifications n WHERE n.related_id = br.borrow_id AND n.type = 'borrow' AND n.title = 'Yêu cầu gia hạn sách'),
                       br.updated_at
                   ) < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    
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

    // 6. Tự động mở khóa tài khoản hết hạn phạt (Hạng mục mới)
    echo "[" . date('Y-m-d H:i:s') . "] Starting Auto-Unlock Users..." . PHP_EOL;
    $unlockSql = "UPDATE users 
                  SET account_status = 'active', 
                      lock_reason = NULL, 
                      locked_at = NULL, 
                      locked_until = NULL 
                  WHERE account_status = 'locked' 
                  AND locked_until IS NOT NULL 
                  AND locked_until < CURDATE()";
    $unlockResult = $db->query($unlockSql)->execute();
    echo "Unlocked " . $unlockResult->getAffectedRows() . " user(s) whose penalty period expired." . PHP_EOL;

    // 7. Gửi email nhắc nhở trả sách (Hạng mục mới: Tự động nhắc trước 2 ngày và hàng ngày sau khi trễ)
    echo "[" . date('Y-m-d H:i:s') . "] Starting Return Reminders..." . PHP_EOL;
    
    // Lấy cấu hình số ngày nhắc trước
    $daysBefore = 2;
    try {
        $settingRes = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'reminder_days_before' LIMIT 1")->execute()->current();
        if ($settingRes) $daysBefore = (int)$settingRes['setting_value'];
    } catch (\Throwable $e) {}

    // 7.1. Tìm các phiếu mượn SẮP ĐẾN HẠN (đúng 2 ngày trước hạn) và chưa nhắc trong hôm nay
    $dueSoonSql = "SELECT br.borrow_id, br.return_date, u.email, u.full_name, b.title AS book_title 
                    FROM borrow_records br
                    JOIN users u ON br.user_id = u.user_id
                    JOIN books b ON br.book_id = b.book_id
                    WHERE br.status = 'borrowed' 
                    AND br.return_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                    AND (br.last_reminded_at IS NULL OR br.last_reminded_at != CURDATE())";
    
    $dueSoonRecords = iterator_to_array($db->query($dueSoonSql)->execute([$daysBefore]));
    
    // 7.2. Tìm các phiếu mượn ĐÃ TRỄ HẠN và chưa nhắc trong hôm nay
    $overdueSql = "SELECT br.borrow_id, br.return_date, u.email, u.full_name, b.title AS book_title 
                    FROM borrow_records br
                    JOIN users u ON br.user_id = u.user_id
                    JOIN books b ON br.book_id = b.book_id
                    WHERE (br.status = 'overdue' OR (br.status = 'borrowed' AND br.return_date < CURDATE()))
                    AND (br.last_reminded_at IS NULL OR br.last_reminded_at != CURDATE())";
    
    $overdueRecords = iterator_to_array($db->query($overdueSql)->execute());

    echo "Found " . count($dueSoonRecords) . " student(s) for Due Soon and " . count($overdueRecords) . " for Overdue." . PHP_EOL;

    $mailedCount = 0;
    $logFile = 'data/cache/mail_previews.log';
    if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0777, true);

    $stmtUpdateReminded = $db->query("UPDATE borrow_records SET last_reminded_at = CURDATE() WHERE borrow_id = ?");

    // Khởi tạo Mail Service
    require_once __DIR__ . '/../module/Library/src/Service/MailService.php';
    $mailService = new \Library\Service\MailService($db);
    $smtpReady = true;
    try {
        $settingRes = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_pass' LIMIT 1")->execute()->current();
        if (!$settingRes || empty($settingRes['setting_value'])) {
            $smtpReady = false;
        }
    } catch (\Throwable $e) { $smtpReady = false; }

    // Process Due Soon
    foreach ($dueSoonRecords as $row) {
        $subject = "[Thư viện HDPE] Sắp đến hạn trả sách: {$row['book_title']}";
        $body = "Chào {$row['full_name']},\n\nCuốn sách '{$row['book_title']}' bạn mượn sẽ đến hạn trả vào ngày " . date('d/m/Y', strtotime($row['return_date'])) . ". "
              . "Vui lòng mang trả đúng hạn hoặc xin gia hạn để tránh bị khóa tài khoản.\n\nTrân trọng,\nThư viện HDPE";
        
        if ($smtpReady) {
            try {
                $mailService->sendEmail($row['email'], $row['full_name'], $subject, $body);
                echo "Sent Due Soon email to {$row['email']}" . PHP_EOL;
            } catch (\Exception $e) {
                echo "Failed to send email to {$row['email']}: " . $e->getMessage() . PHP_EOL;
            }
        } else {
            $content = "To: {$row['email']}\nSubject: $subject\nContent: $body\n--------------------------------------------------\n";
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [DUE SOON]\n" . $content, FILE_APPEND);
        }
        $stmtUpdateReminded->execute([$row['borrow_id']]);
        $mailedCount++;
    }

    // Process Overdue
    foreach ($overdueRecords as $row) {
        $daysLate = (int) floor((time() - strtotime($row['return_date'])) / 86400);
        $subject = "[Thư viện HDPE] CẢNH BÁO TRỄ HẠN: {$row['book_title']}";
        $body = "Chào {$row['full_name']},\n\nBạn đang trả trễ cuốn sách '{$row['book_title']}' được $daysLate ngày. "
              . "Hệ thống sẽ áp dụng quy tắc khóa tài khoản lũy tiến. Vui lòng mang trả sách NGAY LẬP TỨC để tránh bị khóa vĩnh viễn.\n\nTrân trọng,\nThư viện HDPE";
        
        if ($smtpReady) {
            try {
                $mailService->sendEmail($row['email'], $row['full_name'], $subject, $body);
                echo "Sent Overdue email to {$row['email']}" . PHP_EOL;
            } catch (\Exception $e) {
                echo "Failed to send email to {$row['email']}: " . $e->getMessage() . PHP_EOL;
            }
        } else {
            $content = "To: {$row['email']}\nSubject: $subject\nContent: $body\n--------------------------------------------------\n";
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [OVERDUE]\n" . $content, FILE_APPEND);
        }
        $stmtUpdateReminded->execute([$row['borrow_id']]);
        $mailedCount++;
    }

    echo "Reminders processed and logged: {$mailedCount} email(s)." . PHP_EOL;

    // 4. Dọn dẹp thông báo cũ (Hạng mục 1)
    echo "[" . date('Y-m-d H:i:s') . "] Starting Notification Cleanup..." . PHP_EOL;
    $cleanupSql = "DELETE FROM notifications 
                   WHERE (is_read = 1 OR is_deleted = 1) 
                   AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $cleanupResult = $db->query($cleanupSql)->execute();
    echo "Deleted " . $cleanupResult->getAffectedRows() . " old read/deleted notification(s)." . PHP_EOL;

    // 8. Tự động dọn dẹp tài khoản chưa kích hoạt OTP đã hết hạn
    echo "[" . date('Y-m-d H:i:s') . "] Starting Unverified Users Cleanup..." . PHP_EOL;
    $cleanupUsersSql = "DELETE FROM users 
                        WHERE is_approved = 0 
                        AND otp_expires_at IS NOT NULL 
                        AND otp_expires_at < NOW()";
    $cleanupUsersResult = $db->query($cleanupUsersSql)->execute();
    echo "Deleted " . $cleanupUsersResult->getAffectedRows() . " unverified user(s) whose OTP expired." . PHP_EOL;

    // 9. Tự động kiểm tra gia hạn Gmail Watch (mỗi 6 ngày)
    echo "[" . date('Y-m-d H:i:s') . "] Checking Gmail Watch renewal..." . PHP_EOL;
    try {
        $lastWatchRunRes = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gmail_watch_last_run' LIMIT 1")->execute()->current();
        $lastWatchRun = $lastWatchRunRes ? (int)$lastWatchRunRes['setting_value'] : 0;
        
        $renewIntervalSeconds = 6 * 86400; // 6 days
        
        if (time() - $lastWatchRun >= $renewIntervalSeconds) {
            echo "Gmail Watch needs renewal. Attempting register watch..." . PHP_EOL;
            /** @var \Library\Service\GmailService $gmailService */
            $gmailService = $container->get(\Library\Service\GmailService::class);
            $watchResult = $gmailService->registerWatch();
            if ($watchResult) {
                echo "Gmail Watch renewed successfully. Expiration: " . date('Y-m-d H:i:s', (int)($watchResult['expiration'] / 1000)) . PHP_EOL;
            } else {
                echo "Gmail Watch renewal skipped (no credentials set)." . PHP_EOL;
            }
        } else {
            echo "Gmail Watch is up to date." . PHP_EOL;
        }
    } catch (\Throwable $watchEx) {
        echo "Failed to renew Gmail Watch: " . $watchEx->getMessage() . PHP_EOL;
    }

} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
