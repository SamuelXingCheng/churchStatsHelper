<?php
// src/LineService.php
require_once __DIR__ . '/../config.php';

class LineService {
    private $accessToken;

    public function __construct() {
        if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) {
            throw new Exception("LineService Config Error: LINE_CHANNEL_ACCESS_TOKEN is missing.");
        }
        $this->accessToken = LINE_CHANNEL_ACCESS_TOKEN;
    }

    /**
     * 回覆訊息 (Reply API) - Webhook 即時回覆
     */
    public function replyMessage($replyToken, $text) {
        $this->sendMessage('reply', [
            'replyToken' => $replyToken,
            'messages' => [['type' => 'text', 'text' => $text]]
        ]);
    }

    /**
     * 主動推播 (Push API) - Runner 使用
     */
    public function pushMessage($to, $text) {
        if (empty($to)) {
            error_log("[LineService] Push failed: Target ID is empty.");
            return;
        }

        $this->sendMessage('push', [
            'to' => $to,
            'messages' => [['type' => 'text', 'text' => $text]]
        ]);
    }

    /**
     * 統一發送邏輯 (加入錯誤紀錄)
     */
    private function sendMessage($type, $data) {
        $url = "https://api.line.me/v2/bot/message/$type";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . 'Bearer ' . $this->accessToken
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 🔴 關鍵 Log：如果不是 200，就詳細記錄錯誤
        if ($httpCode !== 200) {
            $targetId = $data['to'] ?? ($data['replyToken'] ?? 'N/A');
            error_log("LINE API Push/Reply Error (Type: $type, HTTP $httpCode) to {$targetId}: " . $result);
            // 如果是推播失敗，這裡會明確記錄原因，例如 "bot not in group"
        }
    }
}
?>