<?php
function checkContentLocal(string $text): bool
{
    $badWords = [
        'đm', 'đéo', 'vcl', 'clm', 'chó', 'mẹ mày', 'bố mày', 'lìn', 'lồn', 'cặc', 'buồi', 
        'đmm', 'dkm', 'đkm', 'vkl', 'đcm', 'súc vật', 'óc chó', 'ăn cứt', 'ăn phân', 
        'đĩ', 'phò', 'điếm', 'chịch', 'xoạc', 'cút', 'ngu lờ'
    ];
    
    $cleanText = mb_strtolower($text, 'UTF-8');
    
    foreach ($badWords as $word) {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u';
        if (preg_match($pattern, $cleanText)) {
            echo "Matched word: '$word'\n";
            return false; // Violates moderation
        }
    }
    
    return true; // Safe
}

$testCases = [
    "Hôm nay tôi đi mượn sách ở văn phòng học.", // Should be SAFE (contains "phò" in "phòng")
    "Hành động này rất nhanh chóng.",           // Should be SAFE (contains "chó" in "chóng")
    "Đm thằng chó này láo vãi cả lìn.",         // Should be UNSAFE
    "Mẹ mày điên à?",                           // Should be UNSAFE
    "Tôi không muốn cút đi đâu cả.",            // Should be UNSAFE ("cút")
    "Phòng này nóng quá.",                      // Should be SAFE
];

foreach ($testCases as $text) {
    $res = checkContentLocal($text);
    echo "Text: \"$text\"\nResult: " . ($res ? "SAFE" : "UNSAFE") . "\n\n";
}
