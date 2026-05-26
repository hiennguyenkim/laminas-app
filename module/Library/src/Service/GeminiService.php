<?php

declare(strict_types=1);

namespace Library\Service;

use Laminas\Http\Client;
use RuntimeException;

class GeminiService
{
    private string $apiKey;
    private \Laminas\Db\Adapter\AdapterInterface $adapter;
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    public function __construct(string $apiKey, \Laminas\Db\Adapter\AdapterInterface $adapter)
    {
        $this->apiKey = $apiKey;
        $this->adapter = $adapter;
    }

    /**
     * Gửi yêu cầu đến Gemini API (có cơ chế Cache)
     */
    public function generateResponse(string $prompt, string $systemInstruction = ''): string
    {
        if (empty($this->apiKey)) {
            return "Cấu hình Gemini API Key chưa hoàn tất.";
        }

        // 0. Tự động dọn dẹp Cache cũ (30 ngày) - Xác suất 5%
        if (mt_rand(1, 20) === 1) {
            $this->cleanupOldCache(30);
        }

        // 1. Kiểm tra Cache trước
        $promptHash = hash('sha256', $systemInstruction . '|' . $prompt);
        try {
            $sqlCache = "SELECT response_text FROM ai_responses_cache WHERE prompt_hash = ? LIMIT 1";
            $cacheRow = $this->adapter->query($sqlCache)->execute([$promptHash])->current();
            if ($cacheRow) {
                return $cacheRow['response_text'];
            }
        } catch (\Throwable $e) {}

        // 2. Nếu không có cache, gọi API
        $client = new Client();
        // Dùng query string cho chắc chắn nhất
        $client->setUri($this->apiUrl . '?key=' . $this->apiKey);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type'   => 'application/json'
        ]);

        // Gộp system instruction vào prompt
        $fullPrompt = $prompt;
        if ($systemInstruction !== '') {
            $fullPrompt = "SYSTEM INSTRUCTION: $systemInstruction\n\nUSER MESSAGE: $prompt";
        }

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $fullPrompt]
                    ]
                ]
            ]
        ];

        $client->setRawBody((string)json_encode($data));
        
        try {
            $response = $client->send();
            if (!$response->isSuccess()) {
                return "Lỗi từ Gemini API (Code: " . $response->getStatusCode() . "): " . $response->getBody();
            }

            $result = json_decode($response->getBody(), true);
            $responseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Xin lỗi, tôi không thể trả lời lúc này.";

            // 3. Lưu vào Cache nếu thành công
            if ($responseText !== "Xin lỗi, tôi không thể trả lời lúc này.") {
                try {
                    $sqlInsert = "INSERT IGNORE INTO ai_responses_cache (prompt_hash, prompt_text, response_text) VALUES (?, ?, ?)";
                    $this->adapter->query($sqlInsert)->execute([$promptHash, mb_substr($prompt, 0, 500), $responseText]);
                } catch (\Throwable $e) {}
            }

            return $responseText;
        } catch (\Throwable $e) {
            return "Lỗi kết nối AI: " . $e->getMessage();
        }
    }

    /**
     * Kiểm duyệt nội dung (Moderation)
     * Trả về true nếu nội dung an toàn, false nếu vi phạm
     */
    public function checkContent(string $text): bool
    {
        $prompt = "Hãy phân tích tin nhắn sau đây có chứa ngôn từ thô tục, xúc phạm, thù ghét hoặc không phù hợp với môi trường thư viện trường học không? 
        Chỉ trả về duy nhất từ 'SAFE' nếu an toàn, hoặc 'UNSAFE' nếu vi phạm.
        Tin nhắn: \"$text\"";

        $response = $this->generateResponse($prompt, "Bạn là một chuyên gia kiểm duyệt nội dung cho thư viện đại học.");
        return trim(strtoupper($response)) === 'SAFE';
    }

    public function cleanupOldCache(int $days = 30): void
    {
        try {
            $sql = "DELETE FROM ai_responses_cache WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $this->adapter->query($sql)->execute([$days]);
        } catch (\Throwable $e) {}
    }
}
