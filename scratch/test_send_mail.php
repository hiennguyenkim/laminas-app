<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$db = $app->getServiceManager()->get(Laminas\Db\Adapter\AdapterInterface::class);

$user = $db->query("SELECT * FROM users WHERE username = 'siudeptra'")->execute()->current();

if ($user) {
    $email = $user['email'];
    $name = $user['full_name'];
    
    $content = "To: {$email}\n"
             . "Subject: [Thư viện HDPE] Thử nghiệm hệ thống gửi Mail\n"
             . "Content: Chào {$name}, đây là email thử nghiệm từ hệ thống Thư viện HDPE. Nếu hệ thống SMTP được kết nối, bạn sẽ nhận được email này trong hộp thư thật của mình.\n"
             . "--------------------------------------------------\n";
             
    $logFile = 'data/cache/mail_previews.log';
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [TEST MAIL]\n" . $content, FILE_APPEND);
    
    echo "Đã giả lập gửi thành công!\n\n";
    echo "=== NỘI DUNG EMAIL ===\n";
    echo $content;
} else {
    echo "Không tìm thấy user siudeptra";
}
