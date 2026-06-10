<?php

declare(strict_types=1);

namespace Library\Service;

use Laminas\Http\Client;

class GeminiService
{
    private string $apiKey;
    private \Laminas\Db\Adapter\AdapterInterface $adapter;
    private \Laminas\Db\Adapter\Adapter $db;

    // Gemini (Google) endpoint — fallback nếu không cấu hình provider khác
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    // OpenAI-compatible provider (Kimi, OpenAI, v.v.)
    private string $openaiApiKey  = '';
    private string $openaiModel   = '';
    private string $openaiBaseUrl = 'https://api.moonshot.cn/v1';  // Kimi default

    public function __construct(string $apiKey, \Laminas\Db\Adapter\AdapterInterface $adapter)
    {
        $this->apiKey  = $apiKey;
        $this->adapter = $adapter;
        assert($adapter instanceof \Laminas\Db\Adapter\Adapter);
        $this->db = $adapter;

        // Load OpenAI-compatible provider settings from DB
        $this->loadOpenAiSettings();
    }

    /**
     * Load OpenAI-compatible AI provider settings from system_settings table.
     */
    private function loadOpenAiSettings(): void
    {
        try {
            $rows = $this->db->query(
                "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ai_provider','ai_openai_key','ai_openai_model','ai_openai_base_url')"
            )->execute();

            $settings = [];
            foreach ($rows as $row) {
                $key   = $row['setting_key']   ?? null;
                $value = $row['setting_value'] ?? null;
                if ($key !== null) {
                    $settings[(string)$key] = (string)($value ?? '');
                }
            }

            if (($settings['ai_provider'] ?? '') === 'openai') {
                $this->openaiApiKey  = $settings['ai_openai_key']     ?? '';
                $this->openaiModel   = $settings['ai_openai_model']   ?? 'Kimi-K2.5-FW';
                $this->openaiBaseUrl = rtrim($settings['ai_openai_base_url'] ?? 'https://api.poe.com/v1', '/');
            }
        } catch (\Throwable) {
            // Non-critical: fall back to Gemini
        }
    }

    /**
     * Gửi yêu cầu đến AI API.
     * Nếu provider = openai → dùng OpenAI-compatible API (Kimi, OpenAI, v.v.)
     * Ngược lại → dùng Google Gemini.
     */
    public function generateResponse(string $prompt, string $systemInstruction = '', array $chatHistory = []): string
    {
        // Use OpenAI-compatible provider if configured
        if ($this->openaiApiKey !== '' && $this->openaiModel !== '') {
            return $this->generateOpenAiResponse($prompt, $systemInstruction, $chatHistory);
        }

        // Fallback: Google Gemini
        return $this->generateGeminiResponse($prompt, $systemInstruction, $chatHistory);
    }

    /**
     * Gọi OpenAI-compatible API (Kimi-K2.5, OpenAI GPT, v.v.)
     */
    private function generateOpenAiResponse(string $prompt, string $systemInstruction = '', array $chatHistory = []): string
    {
        if (empty($this->openaiApiKey)) {
            return "Cấu hình AI API Key chưa hoàn tất.";
        }

        // Check cache
        $historyJson = json_encode($chatHistory);
        $promptHash = hash('sha256', $this->openaiModel . '|' . $systemInstruction . '|' . $historyJson . '|' . $prompt);
        $cached = $this->getCache($promptHash);
        if ($cached !== null) {
            return $cached;
        }

        $messages = [];
        if ($systemInstruction !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        foreach ($chatHistory as $msg) {
            $messages[] = [
                'role'    => (string)($msg['role'] ?? 'user'),
                'content' => (string)($msg['content'] ?? ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = [
            'model'       => $this->openaiModel,
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => 2048,
        ];

        $client = new Client();
        $client->setOptions(['timeout' => 20]);
        $client->setUri($this->openaiBaseUrl . '/chat/completions');
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->openaiApiKey,
        ]);
        $client->setRawBody((string) json_encode($data));

        try {
            $response = $client->send();
            if (!$response->isSuccess()) {
                return "Lỗi từ AI API (Code: " . $response->getStatusCode() . "): " . substr($response->getBody(), 0, 200);
            }

            $result = json_decode($response->getBody(), true);
            $responseText = $result['choices'][0]['message']['content']
                ?? $result['choices'][0]['text']
                ?? "Xin lỗi, tôi không thể trả lời lúc này.";

            $this->saveCache($promptHash, $prompt, $responseText);
            return $responseText;

        } catch (\Throwable $e) {
            return "Lỗi kết nối AI: " . $e->getMessage();
        }
    }

    /**
     * Gọi Google Gemini API (legacy).
     */
    private function generateGeminiResponse(string $prompt, string $systemInstruction = '', array $chatHistory = []): string
    {
        if (empty($this->apiKey)) {
            return "Cấu hình Gemini API Key chưa hoàn tất.";
        }

        if (mt_rand(1, 20) === 1) {
            $this->cleanupOldCache(30);
        }

        $historyJson = json_encode($chatHistory);
        $promptHash = hash('sha256', $systemInstruction . '|' . $historyJson . '|' . $prompt);
        $cached = $this->getCache($promptHash);
        if ($cached !== null) {
            return $cached;
        }

        $client = new Client();
        $client->setOptions(['timeout' => 15]);
        $client->setUri($this->apiUrl . '?key=' . $this->apiKey);
        $client->setMethod('POST');
        $client->setHeaders(['Content-Type' => 'application/json']);

        $contents = [];
        $first = true;
        foreach ($chatHistory as $msg) {
            $role = (($msg['role'] ?? 'user') === 'user') ? 'user' : 'model';
            $text = (string)($msg['content'] ?? '');
            if ($first && $systemInstruction !== '') {
                $text = "SYSTEM INSTRUCTION: $systemInstruction\n\nUSER MESSAGE: $text";
                $first = false;
            }
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $text]]
            ];
        }

        $lastText = $prompt;
        if ($first && $systemInstruction !== '') {
            $lastText = "SYSTEM INSTRUCTION: $systemInstruction\n\nUSER MESSAGE: $lastText";
        }
        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $lastText]]
        ];

        $data = [
            'contents' => $contents
        ];

        $client->setRawBody((string) json_encode($data));

        try {
            $response = $client->send();
            if (!$response->isSuccess()) {
                return "Lỗi từ Gemini API (Code: " . $response->getStatusCode() . "): " . $response->getBody();
            }

            $result = json_decode($response->getBody(), true);
            $responseText = $result['candidates'][0]['content']['parts'][0]['text']
                ?? "Xin lỗi, tôi không thể trả lời lúc này.";

            $this->saveCache($promptHash, $prompt, $responseText);
            return $responseText;

        } catch (\Throwable $e) {
            return "Lỗi kết nối AI: " . $e->getMessage();
        }
    }

    // ── Cache helpers ────────────────────────────────────────────────────────

    private function getCache(string $hash): ?string
    {
        try {
            $row = $this->db->query(
                "SELECT response_text FROM ai_responses_cache WHERE prompt_hash = ? LIMIT 1"
            )->execute([$hash])->current();
            return $row ? (string)$row['response_text'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveCache(string $hash, string $promptText, string $responseText): void
    {
        if ($responseText === "Xin lỗi, tôi không thể trả lời lúc này.") {
            return;
        }
        try {
            $this->db->query(
                "INSERT IGNORE INTO ai_responses_cache (prompt_hash, prompt_text, response_text) VALUES (?, ?, ?)"
            )->execute([$hash, mb_substr($promptText, 0, 500), $responseText]);
        } catch (\Throwable) {}
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Kiểm duyệt nội dung (Moderation)
     * Trả về true nếu nội dung an toàn, false nếu vi phạm
     */
    public function checkContent(string $text): bool
    {
        $prompt = "Hãy phân tích tin nhắn sau đây có chứa ngôn từ thô tục, xúc phạm, thù ghét hoặc không phù hợp với môi trường thư viện trường học không? 
        Chỉ trả về duy nhất từ 'SAFE' nếu an toàn, hoặc 'UNSAFE' nếu vi phạm. Tuyệt đối không giải thích gì thêm.
        Tin nhắn: \"$text\"";

        $response = $this->generateResponse($prompt, "Bạn là một chuyên gia kiểm duyệt nội dung cho thư viện đại học. Bạn chỉ trả về duy nhất từ 'SAFE' hoặc 'UNSAFE'.");
        $trimmedResponse = trim($response);

        // Nếu có lỗi kết nối hoặc cấu hình, không chặn toàn bộ chat của người dùng.
        // Thay vào đó, chạy bộ lọc từ cấm cục bộ đơn giản để dự phòng.
        if (
            str_starts_with($trimmedResponse, 'Lỗi kết nối AI')
            || str_starts_with($trimmedResponse, 'Lỗi từ Gemini')
            || str_starts_with($trimmedResponse, 'Lỗi từ AI')
            || str_starts_with($trimmedResponse, 'Cấu hình Gemini')
            || str_starts_with($trimmedResponse, 'Cấu hình AI')
        ) {
            $badWords = [
                'đm', 'đéo', 'vcl', 'clm', 'chó', 'mẹ mày', 'bố mày',
                'lìn', 'lồn', 'cặc', 'buồi', 'đmm', 'dkm', 'đkm', 'vkl',
                'đcm', 'súc vật', 'óc chó', 'ăn cứt', 'ăn phân',
                'đĩ', 'phò', 'điếm', 'chịch', 'xoạc', 'cút', 'ngu lờ'
            ];
            $cleanText = mb_strtolower($text, 'UTF-8');
            foreach ($badWords as $word) {
                $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote($word, '/') . '(?![\\p{L}\\p{N}])/u';
                if (preg_match($pattern, $cleanText)) {
                    return false;
                }
            }
            return true;
        }

        $cleanResponse = preg_replace('/[^A-Z]/', '', strtoupper($trimmedResponse));
        if (str_contains($cleanResponse, 'UNSAFE')) return false;
        if (str_contains($cleanResponse, 'SAFE'))   return true;
        return true;
    }

    public function cleanupOldCache(int $days = 30): void
    {
        try {
            $this->db->query(
                "DELETE FROM ai_responses_cache WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            )->execute([$days]);
        } catch (\Throwable) {}
    }
}
