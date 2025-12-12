<?php
// runner.php (正則版)

ignore_user_abort(true);
set_time_limit(120); 

// [Log] 腳本啟動
error_log("[Runner] Script started (Regex Mode).");

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
// require_once __DIR__ . '/src/GeminiService.php'; // 停用 AI
require_once __DIR__ . '/src/RegexService.php';  // 啟用 正則
require_once __DIR__ . '/src/StatsService.php';
require_once __DIR__ . '/src/LineService.php';

try {
    $db = Database::getInstance()->getConnection();
    // $geminiService = new GeminiService();
    $regexService = new RegexService(); // 改用這個
    $statsService = new StatsService();
    $lineService = new LineService();
} catch (Exception $e) {
    error_log("[Runner] Init Error: " . $e->getMessage());
    exit;
}

// 2. 撈取待處理的工作
$db->beginTransaction();
$stmt = $db->prepare("SELECT * FROM processing_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
$stmt->execute();
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    $db->rollBack();
    exit;
}

$id = $task['id'];
error_log("[Runner] Processing Task ID: {$id}"); 

// 3. 標記為處理中
$updateStmt = $db->prepare("UPDATE processing_queue SET status = 'processing' WHERE id = ?");
$updateStmt->execute([$id]);
$db->commit();

try {
    $rawText = $task['message_text'];
    $groupId = $task['line_group_id'];
    $userId = $task['line_user_id'];
    // 修正：如果是個人聊天 (groupId 為空)，就回覆給 userId
    $targetId = !empty($groupId) ? $groupId : $userId;

    error_log("[Runner] Parsing text with Regex for Task {$id}..."); 

    // A. 改用 Regex 解析
    $parsedData = $regexService->parseStats($rawText);

    if ($parsedData && is_array($parsedData) && count($parsedData) > 0) {
        error_log("[Runner] Regex parsed success. Items count: " . count($parsedData)); 
        
        // B. 寫入統計資料庫
        // 注意：這裡傳入 $groupId 和 $userId，StatsService 已修正為允許 NULL
        $count = $statsService->saveReports($groupId, $userId, $parsedData, $rawText);
        
        error_log("[Runner] DB saved rows: {$count}"); 

        if ($count > 0) {
            // C. 組裝回覆訊息
            $replyText = "✅ [統計完成] 已儲存 {$count} 筆資料\n";
            foreach ($parsedData as $d) {
                $replyText .= "----------------\n";
                // 正則版通常抓不到 main_district，所以只顯示 sub_district
                $replyText .= "📌 {$d['sub_district']}\n";
                $replyText .= "聖:{$d['saint']} | 福:{$d['gospel']} | 新:{$d['new']}\n";
            }
            
            // D. 推播回覆
            $lineService->pushMessage($targetId, trim($replyText));
            
        } else {
            // 解析有資料但寫入 0 筆 (通常不會發生，除非 sub_district 為空)
            error_log("[Runner] Warning: Regex matched but DB save count is 0.");
            $lineService->pushMessage($targetId, "⚠️ 格式似乎有誤，請確認是否包含「聖徒」、「福音」等關鍵字。");
        }
    } else {
        // Regex 沒抓到任何東西
        error_log("[Runner] Regex returned empty. Format mismatch?");
        // 選擇性回覆，避免誤判一般聊天訊息
        // $lineService->pushMessage($targetId, "❓ 無法識別統計格式，請檢查換行或冒號。");
    }

    // 5. 標記完成
    $db->prepare("UPDATE processing_queue SET status = 'completed' WHERE id = ?")->execute([$id]);
    error_log("[Runner] Task {$id} COMPLETED.");

} catch (Exception $e) {
    error_log("[Runner] Error on Task {$id}: " . $e->getMessage());
    $db->prepare("UPDATE processing_queue SET status = 'error' WHERE id = ?")->execute([$id]);
}
?>