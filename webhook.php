<?php
// webhook.php (加入 Log 版)
set_time_limit(10); 

// [Log] Webhook 被呼叫
// error_log("[Webhook] Received request."); 

require_once 'config.php';
require_once 'src/Database.php';

$input = file_get_contents('php://input');
$events = json_decode($input, true)['events'] ?? [];

if (empty($events)) {
    echo "OK";
    exit;
}

$db = Database::getInstance()->getConnection();
$hasNewTask = false;

foreach ($events as $event) {
    if ($event['type'] !== 'message' || $event['message']['type'] !== 'text') {
        continue;
    }

    $text = trim($event['message']['text']);
    
    // 關鍵字過濾
    if (strpos($text, '兒童排') !== false || strpos($text, '統計') !== false) {
        
        $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
        $userId = $event['source']['userId'] ?? '';

        // [Log] 收到符合條件的訊息
        error_log("[Webhook] Stats keyword found from User: {$userId}, Group: {$groupId}");

        // 存入佇列
        $stmt = $db->prepare("INSERT INTO processing_queue (line_group_id, line_user_id, message_text, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$groupId, $userId, $text]);
        
        $hasNewTask = true;
    }
}

// 觸發 Runner
if ($hasNewTask) {
    triggerRunner();
}

echo "OK";
exit;

// --- 輔助函式 (CLI Command 模式) ---
function triggerRunner() {
    // 1. 取得 runner.php 的絕對路徑
    // __DIR__ 會抓到 webhook.php 所在的資料夾，例如 /home/uflifdgq/public_html/churchStatsHelper
    $runnerPath = __DIR__ . '/runner.php'; 

    // 2. 檢查檔案是否存在 (防呆)
    if (!file_exists($runnerPath)) {
        error_log("[Webhook] Error: Runner file not found at $runnerPath");
        return;
    }

    // 3. 組合指令
    // "php" 是執行指令
    // "> /dev/null 2>&1" 代表把輸出丟掉，不等待回應
    // "&" 代表在背景執行
    $command = "php " . escapeshellarg($runnerPath) . " > /dev/null 2>&1 &";

    // [Log] 記錄執行的指令
    error_log("[Webhook] Triggering via CLI: $command");
    
    // 4. 執行
    // 注意：部分虛擬主機可能封鎖 exec 函式，如果 Log 顯示觸發但沒跑，可能需聯絡主機商開啟 exec
    exec($command);
}
?>