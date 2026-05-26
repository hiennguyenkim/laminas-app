<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$db = $app->getServiceManager()->get(Laminas\Db\Adapter\AdapterInterface::class);

require 'module/Library/src/Service/MailService.php';
$mailService = new \Library\Service\MailService($db);

$user = $db->query("SELECT * FROM users WHERE username = 'siudeptra'")->execute()->current();

if ($user) {
    echo "Chuẩn bị gửi thư thật tới: " . $user['email'] . "\n";
    try {
        $mailService->sendEmail(
            $user['email'], 
            $user['full_name'], 
            "[Thư viện HDPE] Cảnh báo mượn sách", 
            "Chào bạn, đây là hệ thống email thật."
        );
        echo "Gửi thành công!\n";
    } catch (\Exception $e) {
        echo "LỖI: " . $e->getMessage() . "\n";
    }
}
