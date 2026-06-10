<?php

declare(strict_types=1);

// Run with:
// To get URL: php bin/get-gmail-token.php
// To save token: php bin/get-gmail-token.php <code>

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

use Library\Service\GmailService;

try {
    $container = require 'config/container.php';
    /** @var GmailService $gmailService */
    $gmailService = $container->get(GmailService::class);

    $args = $_SERVER['argv'] ?? [];
    if (count($args) < 2) {
        $authUrl = $gmailService->getAuthUrl();
        echo "=================================================================" . PHP_EOL;
        echo "GMAIL OAUTH2 TOKEN GENERATION" . PHP_EOL;
        echo "=================================================================" . PHP_EOL;
        echo "1. Mở URL dưới đây trong trình duyệt của bạn:" . PHP_EOL;
        echo $authUrl . PHP_EOL . PHP_EOL;
        echo "2. Đăng nhập và đồng ý cấp quyền truy cập Gmail." . PHP_EOL;
        echo "3. Copy mã Authorization Code hiển thị trên trình duyệt." . PHP_EOL;
        echo "4. Chạy lại script này kèm mã đó:" . PHP_EOL;
        echo "   php bin/get-gmail-token.php MÃ_CỦA_BẠN" . PHP_EOL;
        echo "=================================================================" . PHP_EOL;
        exit(0);
    }

    $code = trim($args[1]);
    echo "Đang xác thực mã code..." . PHP_EOL;
    
    $token = $gmailService->authenticateCode($code);
    
    if (isset($token['error'])) {
        echo "LỖI xác thực: " . ($token['error_description'] ?? $token['error']) . PHP_EOL;
        exit(1);
    }

    echo "Thành công!" . PHP_EOL;
    echo "Refresh Token đã được tự động lưu vào bảng system_settings." . PHP_EOL;
    echo "Thông tin Token chi tiết:" . PHP_EOL;
    print_r($token);
    
} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
