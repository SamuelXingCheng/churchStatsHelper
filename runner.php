<?php
// runner.php (Final Debug Version)

// 1. Environment Setup
ignore_user_abort(true);
set_time_limit(120);

// 2. Load Dependencies
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/RegexService.php';
require_once __DIR__ . '/src/StatsService.php';
require_once __DIR__ . '/src/LineService.php';
require_once __DIR__ . '/src/GoogleSheetsService.php';

// 3. Initialize Services (CRITICAL FIX)
try {
    $db = Database::getInstance()->getConnection();
    $regexService = new RegexService();
    $statsService = new StatsService();
    $lineService = new LineService();
    $sheetsService = new GoogleSheetsService();
} catch (Exception $e) {
    error_log("[Runner] Init Error: " . $e->getMessage());
    exit;
}

// 4. Fetch Task (Modified to pick the LATEST task for debugging)
$db->beginTransaction();
// Changed to DESC to prioritize your newest message immediately
$stmt = $db->prepare("SELECT * FROM processing_queue WHERE status = 'pending' ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
$stmt->execute();
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    $db->rollBack();
    exit;
}

$id = $task['id'];
$updateStmt = $db->prepare("UPDATE processing_queue SET status = 'processing' WHERE id = ?");
$updateStmt->execute([$id]);
$db->commit();

// 5. Process Task
try {
    $rawText = $task['message_text'];
    $groupId = $task['line_group_id'];
    $userId = $task['line_user_id'];
    $targetId = !empty($groupId) ? $groupId : $userId;

    // [DEBUG] Log Raw Input
    $logRawText = str_replace(array("\n", "\r"), ['\n', '\r'], $rawText);
    error_log("==================================================");
    error_log("[Runner DEBUG] Task ID: {$id}");
    error_log("[Runner DEBUG] 1. Raw Input: [{$logRawText}]");

    // A. Regex Parse
    $parsedData = $regexService->parseStats($rawText);

    // [DEBUG] Log Parsed Data
    error_log("[Runner DEBUG] 2. Parsed Data: " . json_encode($parsedData, JSON_UNESCAPED_UNICODE));

    if ($parsedData && is_array($parsedData) && count($parsedData) > 0) {
        
        // B. Save to DB (Optional during debug)
        $count = count($parsedData); 

        if ($count > 0) {
            
            // C. Sync to Google Sheets
            try {
                error_log("[Runner DEBUG] 3. Calling Google Sheets Service...");
                $sheetsService->appendStats($parsedData);
            } catch (Throwable $e) {
                error_log("[Runner] Sheets Sync Failed: " . $e->getMessage());
            }

            // D. Prepare Reply (Muted for now)
            $replyText = "✅ [Debug Mode] Data parsed.\n";
            foreach ($parsedData as $d) {
                $replyText .= "📌 {$d['sub_district']} (Week: {$d['week']})\n";
                $replyText .= "Stats: {$d['saint']} / {$d['gospel']} / {$d['new']}\n";
            }
            error_log("[Runner DEBUG] 4. Prepared Reply: \n" . $replyText);
            error_log("[Runner DEBUG] 5. 🛑 Line Push Muted.");
            
        } else {
            error_log("[Runner] Parsed data count is 0.");
        }
    } else {
        error_log("[Runner] No valid stats found.");
    }

    // 6. Complete
    $db->prepare("UPDATE processing_queue SET status = 'completed' WHERE id = ?")->execute([$id]);
    error_log("[Runner] Task {$id} COMPLETED.");

} catch (Exception $e) {
    error_log("[Runner] Fatal Error on Task {$id}: " . $e->getMessage());
    $db->prepare("UPDATE processing_queue SET status = 'error' WHERE id = ?")->execute([$id]);
}
?>