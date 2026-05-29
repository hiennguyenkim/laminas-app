<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$db = $app->getServiceManager()->get(Laminas\Db\Adapter\AdapterInterface::class);

try {
    $db->query("UPDATE system_settings SET setting_value = 'Thư Viện HDPE' WHERE setting_key = 'smtp_from_name'")->execute();
    echo "Đã cập nhật smtp_from_name thành 'Thư Viện HDPE' thành công!\n";
    
    // Double check
    $row = $db->query("SELECT * FROM system_settings WHERE setting_key = 'smtp_from_name'")->execute()->current();
    echo "Giá trị mới: '{$row['setting_value']}'\n";
} catch (Exception $e) {
    echo "Lỗi: " . $e->getMessage() . "\n";
}
