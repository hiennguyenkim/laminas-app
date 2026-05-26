<?php
declare(strict_types=1);

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use Laminas\Mvc\Application;
use Library\Model\Table\NotificationTable;

$appConfig = require 'config/application.config.php';
$app = Application::init($appConfig);
$container = $app->getServiceManager();

/** @var NotificationTable $table */
$table = $container->get(NotificationTable::class);
/** @var \Laminas\Db\Adapter\AdapterInterface $db */
$db = $container->get(\Laminas\Db\Adapter\AdapterInterface::class);

echo "--- THỬ NGHIỆM XÓA THÔNG BÁO ---\n";

// 1. Tạo thông báo mẫu (personal) cho user 1
try {
    $table->insertNotification(1, null, 'Test Personal', 'Xóa tôi đi', 'ticket', 1);
    $res = $db->query("SELECT id FROM notifications WHERE title = 'Test Personal' ORDER BY id DESC LIMIT 1")->execute()->current();
    if ($res) {
        $notiId = (int)$res['id'];
        echo "1. Đã tạo thông báo personal ID: $notiId\n";
        
        $table->deleteNotification($notiId, 1);
        $check = $db->query("SELECT 1 FROM notifications WHERE id = ?")->execute([$notiId]);
        if ($check->count() === 0) {
            echo "   -> XÓA PERSONAL: [THÀNH CÔNG]\n";
        } else {
            echo "   -> XÓA PERSONAL: [THẤT BẠI]\n";
        }
    } else {
        echo "1. Không tạo được thông báo.\n";
    }
} catch (\Throwable $e) {
    echo "1. Lỗi: " . $e->getMessage() . "\n";
}

// 2. Thử nghiệm ẩn thông báo chung cho user 1
try {
    $table->insertNotification(null, null, 'Test Broadcast', 'Ẩn tôi đi', 'borrow_approved', 1);
    $res = $db->query("SELECT id FROM notifications WHERE title = 'Test Broadcast' ORDER BY id DESC LIMIT 1")->execute()->current();
    if ($res) {
        $bcId = (int)$res['id'];
        echo "2. Đã tạo thông báo broadcast ID: $bcId\n";
        
        $table->deleteNotification($bcId, 1);
        $checkHide = $db->query("SELECT 1 FROM user_notifications_hidden WHERE notification_id = ? AND user_id = 1")->execute([$bcId]);
        if ($checkHide->count() > 0) {
            echo "   -> ẨN BROADCAST: [THÀNH CÔNG]\n";
        } else {
            echo "   -> ẨN BROADCAST: [THẤT BẠI]\n";
        }
        
        // Dọn dẹp
        $db->query("DELETE FROM notifications WHERE id = ?")->execute([$bcId]);
    } else {
        echo "2. Không tạo được thông báo.\n";
    }
} catch (\Throwable $e) {
    echo "2. Lỗi: " . $e->getMessage() . "\n";
}
?>
