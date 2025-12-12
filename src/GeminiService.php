<?php
// src/GeminiService.php
require_once __DIR__ . '/../config.php';

class GeminiService {
    private $apiKey;
    private $model;

    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
        $this->model = GEMINI_MODEL;
    }

    public function parseStats(string $text): ?array {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        // 定義 Prompt，教導 AI 如何讀懂召會統計格式
        $prompt = <<<EOD
你是一個專業的資料結構化助手。你的任務是從用戶提供的文字中提取「兒童排統計數據」，並輸出為 JSON 陣列。

【輸入範例】
9/15-9/21
十七大區兒童排
聖徒兒童數：5
福音兒童數：0
新接觸三次以上：0

二三大區
仁化會所兒童排
聖徒兒童：3
福音兒童：5
新接觸三次以上：5

【輸出規則】
1. 回傳一個 JSON Array，每個物件包含以下欄位：
   - `week`: 統計週次 (例如 "9/15-9/21")，若無則留空或從上下文推斷。
   - `main_district`: 大區名稱 (例如 "十七大區", "二四大區")。若該行只有小區，請嘗試從上文推斷大區，若無法推斷則留空。
   - `sub_district`: 小區或排名稱 (例如 "仁化會所兒童排", "幼幼排")。
   - `saint`: 聖徒兒童數 (數字)。
   - `gospel`: 福音兒童數 (數字)。
   - `new`: 新接觸三次以上 (數字)。
2. 只輸出純 JSON，不要 Markdown 標記，不要解釋。
3. 若數字為 0 也要明確輸出 0。

【用戶輸入】
{$text}
EOD;

        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.2, // 低隨機性，確保格式穩定
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Gemini API Error: " . $response);
            return null;
        }

        $result = json_decode($response, true);
        $textResult = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        // 清理 JSON 字串 (有時候 AI 會包 ```json ...)
        $textResult = str_replace(['```json', '```'], '', $textResult);
        
        return json_decode(trim($textResult), true);
    }
}
?>