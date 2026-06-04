<?php

declare(strict_types=1);

use Laminas\Mvc\Application;

chdir(dirname(__DIR__));

/**
 * Laminas standard initialization.
 * Base URL detection works automatically when REQUEST_URI and SCRIPT_NAME are kept intact.
 */
if (php_sapi_name() === 'cli-server') {
    $parsedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uriPath    = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
    $file       = __DIR__ . $uriPath;
    if ($uriPath !== '/' && is_file($file)) {
        return false; // serve static files directly
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

// Composer autoloading
include __DIR__ . '/../vendor/autoload.php';

if (! class_exists(Application::class)) {
    throw new RuntimeException("Unable to load application. Run `composer install` first.");
}

/** 
// Fix Base URL detection for subdirectory hosting without '/public' in URL
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/public/index.php') !== false) {
    $_SERVER['SCRIPT_NAME'] = str_replace('/public/index.php', '/index.php', $_SERVER['SCRIPT_NAME']);
    $_SERVER['PHP_SELF'] = str_replace('/public/index.php', '/index.php', $_SERVER['PHP_SELF'] ?? '');
}

// Ensure the Request object doesn't include /public in the base path
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    // Tricking Laminas to think the script is in the root project dir
    $_SERVER['SCRIPT_FILENAME'] = str_replace('public/index.php', 'index.php', str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']));
}
**/

$container = require __DIR__ . '/../config/container.php';

// Hỗ trợ nâng cấp Database bằng trình duyệt (Chỉ kích hoạt khi truyền đúng token bảo mật)
if (isset($_GET['migrate_db']) && $_GET['migrate_db'] === 'hdpe_upgrade_utf8mb4_2026') {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        echo "Bắt đầu nâng cấp cơ sở dữ liệu..." . PHP_EOL;
        /** @var \Laminas\Db\Adapter\AdapterInterface $db */
        $db = $container->get(\Laminas\Db\Adapter\AdapterInterface::class);
        $dbNameRes = $db->query("SELECT DATABASE() AS db_name")->execute()->current();
        $dbName = $dbNameRes['db_name'] ?? null;
        if (!$dbName) {
            throw new \Exception("Không thể kết nối hoặc xác định tên Database.");
        }
        echo "Database: " . $dbName . PHP_EOL;
        $db->query("ALTER DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();
        echo "Đã nâng cấp charset Database thành utf8mb4." . PHP_EOL;

        $tables = [
            'public_chats', 'users', 'book_reviews', 'ticket_messages', 'support_tickets',
            'notifications', 'announcements', 'chat_logs', 'books', 'user_notifications_read',
            'user_notifications_hidden', 'ai_responses_cache', 'book_categories', 'system_settings', 'penalty_logs'
        ];
        foreach ($tables as $table) {
            $tableCheck = $db->query("SHOW TABLES LIKE ?")->execute([$table])->current();
            if ($tableCheck) {
                echo "Đang nâng cấp bảng `$table`..." . PHP_EOL;
                $db->query("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();
            }
        }
        echo "Chúc mừng! Nâng cấp cơ sở dữ liệu hoàn tất thành công!" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "LỖI: " . $e->getMessage() . PHP_EOL;
    }
    exit;
}

// Hỗ trợ đồng bộ dữ liệu local lên Render (Chỉ kích hoạt khi truyền đúng token bảo mật)
if (isset($_GET['sync_db']) && $_GET['sync_db'] === 'hdpe_sync_data_2026') {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        echo "Bắt đầu đồng bộ cơ sở dữ liệu từ local..." . PHP_EOL;
        /** @var \Laminas\Db\Adapter\AdapterInterface $db */
        $db = $container->get(\Laminas\Db\Adapter\AdapterInterface::class);
        $connection = $db->getDriver()->getConnection();
        $connection->connect();
        $pdo = $connection->getResource();

        if (!$pdo instanceof \PDO) {
            throw new \Exception("Kết nối Database không hỗ trợ PDO.");
        }

        $sqlFile = dirname(__DIR__) . '/data_sync.sql';
        if (!file_exists($sqlFile)) {
            throw new \Exception("Không tìm thấy file dữ liệu data_sync.sql. Vui lòng deploy tệp này lên Render.");
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \Exception("Không thể đọc file data_sync.sql.");
        }

        echo "Đang đọc file dữ liệu (" . round(strlen($sql) / 1024, 2) . " KB)..." . PHP_EOL;
        
        echo "Đang thực thi các câu lệnh SQL..." . PHP_EOL;
        $pdo->exec($sql);
        
        echo "Chúc mừng! Đồng bộ dữ liệu thành công!" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "LỖI: " . $e->getMessage() . PHP_EOL;
    }
    exit;
}


/** @var Application $app */
$app = $container->get('Application');
$app->run();
