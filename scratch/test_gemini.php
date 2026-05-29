<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Library\Service\GeminiService;
use Laminas\Db\Adapter\Adapter;

$config = include __DIR__ . '/../config/autoload/local.php';
$globalConfig = include __DIR__ . '/../config/autoload/global.php';
$dbConfig = array_merge($globalConfig['db'], $config['db']);

$adapter = new Adapter($dbConfig);
$apiKey = $config['gemini']['api_key'] ?? '';

echo "API Key: " . $apiKey . "\n";

$gemini = new GeminiService($apiKey, $adapter);

$test1 = "Hôm nay tôi đi mượn sách ở thư viện.";
$test2 = "Đm thằng chó này láo vãi cả lìn.";

echo "Test 1 ('$test1'):\n";
$res1 = $gemini->checkContent($test1);
echo "Result 1: " . ($res1 ? "SAFE" : "UNSAFE") . "\n\n";

echo "Test 2 ('$test2'):\n";
$res2 = $gemini->checkContent($test2);
echo "Result 2: " . ($res2 ? "SAFE" : "UNSAFE") . "\n\n";

// Let's call generateResponse directly to see the raw output from Gemini
echo "Raw response for Test 2:\n";
$prompt = "Hãy phân tích tin nhắn sau đây có chứa ngôn từ thô tục, xúc phạm, thù ghét hoặc không phù hợp với môi trường thư viện trường học không? 
Chỉ trả về duy nhất từ 'SAFE' nếu an toàn, hoặc 'UNSAFE' nếu vi phạm. Tuyệt đối không giải thích gì thêm.
Tin nhắn: \"$test2\"";
$raw = $gemini->generateResponse($prompt, "Bạn là một chuyên gia kiểm duyệt nội dung cho thư viện đại học. Bạn chỉ trả về duy nhất từ 'SAFE' hoặc 'UNSAFE'.");
echo "Raw: " . $raw . "\n";
