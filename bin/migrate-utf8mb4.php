<?php

declare(strict_types=1);

// Chạy script này bằng lệnh: php bin/migrate-utf8mb4.php

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use Laminas\Db\Adapter\AdapterInterface;

try {
    $container = require 'config/container.php';
    echo "Đang khởi tạo kết nối cơ sở dữ liệu..." . PHP_EOL;
    
    /** @var AdapterInterface $db */
    $db = $container->get(AdapterInterface::class);

    // Lấy tên database hiện tại từ kết nối
    $dbNameRes = $db->query("SELECT DATABASE() AS db_name")->execute()->current();
    $dbName = $dbNameRes['db_name'] ?? null;
    
    if (!$dbName) {
        throw new \Exception("Không thể xác định tên Database từ kết nối.");
    }
    
    echo "Cơ sở dữ liệu đích: " . $dbName . PHP_EOL;

    // 1. Chuyển đổi Database charset
    echo "Đang chuyển đổi Charset của Database sang utf8mb4..." . PHP_EOL;
    $db->query("ALTER DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();

    // Danh sách các bảng cần convert
    $tables = [
        'public_chats',
        'users',
        'book_reviews',
        'ticket_messages',
        'support_tickets',
        'notifications',
        'announcements',
        'chat_logs',
        'books',
        'user_notifications_read',
        'user_notifications_hidden',
        'ai_responses_cache',
        'book_categories',
        'system_settings',
        'penalty_logs'
    ];

    foreach ($tables as $table) {
        // Kiểm tra xem bảng có tồn tại không trước khi ALTER
        $tableCheck = $db->query("SHOW TABLES LIKE ?")->execute([$table])->current();
        if ($tableCheck) {
            echo "Đang chuyển đổi bảng `$table` sang utf8mb4..." . PHP_EOL;
            $db->query("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();
        } else {
            echo "Bảng `$table` không tồn tại, bỏ qua." . PHP_EOL;
        }
    }

    echo "Chúc mừng! Quá trình chuyển đổi sang utf8mb4 đã hoàn tất thành công!" . PHP_EOL;
} catch (\Throwable $e) {
    echo "LỖI: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
