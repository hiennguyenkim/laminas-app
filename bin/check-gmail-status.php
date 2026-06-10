<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
chdir(dirname(__DIR__));
require 'vendor/autoload.php';

$container = require 'config/container.php';
$settingsTable = $container->get(\Library\Model\Table\SystemSettingsTable::class);

// Check Gmail config
$clientId        = $settingsTable->getSetting('gmail_client_id');
$clientSecret    = $settingsTable->getSetting('gmail_client_secret');
$refreshToken    = $settingsTable->getSetting('gmail_refresh_token');
$accessTokenJson = $settingsTable->getSetting('gmail_access_token');

echo "=== Gmail Config Status ===" . PHP_EOL;
echo "client_id:     " . ($clientId ? substr($clientId, 0, 20) . '...' : '(TRỐNG - chưa cấu hình)') . PHP_EOL;
echo "client_secret: " . ($clientSecret ? '***set***' : '(TRỐNG)') . PHP_EOL;
echo "refresh_token: " . ($refreshToken ? '***set*** (' . strlen($refreshToken) . ' chars)' : '(TRỐNG - CẦN XÁC THỰC GMAIL)') . PHP_EOL;

if ($accessTokenJson) {
    $token = json_decode($accessTokenJson, true);
    $created  = (int)($token['created'] ?? 0);
    $expires  = (int)($token['expires_in'] ?? 3600);
    $expireAt = $created + $expires;
    echo "access_token:  ***set***" . PHP_EOL;
    echo "token_created: " . ($created ? date('Y-m-d H:i:s', $created) : 'N/A') . PHP_EOL;
    echo "token_expires: " . ($expireAt ? date('Y-m-d H:i:s', $expireAt) : 'N/A') . PHP_EOL;
    echo "token_status:  " . (time() > $expireAt ? '❌ HẾT HẠN (sẽ tự làm mới qua refresh_token)' : '✅ Còn hạn') . PHP_EOL;
    if (isset($token['error'])) {
        echo "token_error:   " . $token['error'] . " - " . ($token['error_description'] ?? '') . PHP_EOL;
    }
} else {
    echo "access_token:  (TRỐNG)" . PHP_EOL;
}

// Quick connectivity test
echo PHP_EOL . "=== Connectivity Test ===" . PHP_EOL;
$ch = curl_init('https://oauth2.googleapis.com/');
curl_setopt_array($ch, [
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY         => true,
    CURLOPT_SSL_VERIFYPEER => true,
]);
curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrNo = curl_errno($ch);
curl_close($ch);

if ($curlError) {
    echo "Google OAuth2: ❌ KHÔNG KẾT NỐI - [" . $curlErrNo . "] " . $curlError . PHP_EOL;
    echo PHP_EOL . ">>> Khả năng cao: Firewall hoặc proxy chặn HTTPS ra ngoài." . PHP_EOL;
    echo ">>> Giải pháp: Dùng nút 'Tôi đã chuyển khoản xong' trong trang checkout." . PHP_EOL;
} else {
    echo "Google OAuth2: ✅ Kết nối OK (HTTP " . $httpCode . ")" . PHP_EOL;
    
    if ($refreshToken) {
        echo PHP_EOL . "=== Token Refresh Test ===" . PHP_EOL;
        // Try to refresh token with short timeout
        $postData = http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        $ch2 = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch2, [
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response  = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $curlErr2  = curl_error($ch2);
        curl_close($ch2);

        if ($curlErr2) {
            echo "Refresh: ❌ Lỗi cURL: " . $curlErr2 . PHP_EOL;
        } else {
            $result = json_decode($response, true);
            if (isset($result['access_token'])) {
                echo "Refresh: ✅ Token làm mới thành công!" . PHP_EOL;
            } elseif (isset($result['error'])) {
                echo "Refresh: ❌ Lỗi: " . $result['error'] . " - " . ($result['error_description'] ?? '') . PHP_EOL;
                if ($result['error'] === 'invalid_grant') {
                    echo ">>> Refresh token đã hết hạn hoặc bị thu hồi." . PHP_EOL;
                    echo ">>> Cần vào Admin → Cài đặt → Gmail → Xác thực lại." . PHP_EOL;
                }
            } else {
                echo "Refresh: ⚠️  Phản hồi không mong đợi: " . $response . PHP_EOL;
            }
        }
    }
}
