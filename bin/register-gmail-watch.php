<?php

declare(strict_types=1);

// Run with: php bin/register-gmail-watch.php

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

use Library\Service\GmailService;

try {
    $container = require 'config/container.php';
    /** @var GmailService $gmailService */
    $gmailService = $container->get(GmailService::class);

    echo "Bắt đầu đăng ký Gmail Push Notification watch với Google Pub/Sub..." . PHP_EOL;

    $result = $gmailService->registerWatch();

    if ($result) {
        echo "Đăng ký Watch thành công!" . PHP_EOL;
        echo "History ID: " . $result['historyId'] . PHP_EOL;
        echo "Thời hạn hết hạn (milli-seconds): " . $result['expiration'] . PHP_EOL;
        echo "Thời hạn hết hạn (datetime): " . date('Y-m-d H:i:s', (int)($result['expiration'] / 1000)) . PHP_EOL;
    } else {
        echo "LỖI: Không thể đăng ký watch. Vui lòng kiểm tra lại cấu hình client id/secret/refresh token/topic name." . PHP_EOL;
        exit(1);
    }
} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
