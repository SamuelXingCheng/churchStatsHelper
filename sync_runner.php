<?php
// sync_runner.php (雙向同步完整版)
set_time_limit(0); 
ini_set('memory_limit', '256M');
// 確保錯誤會寫入系統 Log，方便日後查帳
ini_set('log_errors', 1);
ini_set('display_errors', 1); 
error_reporting(E_ALL);

require_once __DIR__ . '/src/AttendanceService.php';

// 自定義 Logger，同時輸出到螢幕與 Log 檔
function logger($msg) {
    $timestamp = date("Y-m-d H:i:s");
    echo "[$timestamp] [SyncRunner] $msg\n";
    error_log("[SyncRunner] $msg");
}

try {
    logger("啟動背景同步 (雙向模式)...");

    $service = new AttendanceService();
    $db = Database::getInstance()->getConnection();

    $cookieFile = __DIR__ . "/cookie/central_cookie.tmp";
    if (!file_exists($cookieFile)) {
        logger("⚠️ 警告：找不到 Cookie 檔案，同步可能會失敗。");
    }

    // ★ 修改 1：單純抓 synced = 0 的資料 (無論是出席還是取消)
    $sql = "SELECT * FROM attendance_records WHERE synced = 0 LIMIT 100";
    $stmt = $db->query($sql);
    $pendingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingList)) {
        logger("目前無待同步紀錄，程式結束。");
        exit;
    }

    logger("發現 " . count($pendingList) . " 筆待同步資料，正在進行分組...");

    // 2. 分組邏輯：依「聚會+日期」分組，並區分「出席」與「取消」
    $batches = [];

    foreach ($pendingList as $row) {
        $key = "{$row['item_id']}_{$row['year']}_{$row['week']}";
        
        if (!isset($batches[$key])) {
            $batches[$key] = [
                'item_id' => $row['item_id'],
                'year'    => $row['year'],
                'week'    => $row['week'],
                'add_ids' => [],      // 要設為出席的人 (status = 1)
                'del_ids' => [],      // 要設為缺席的人 (status = NULL/0)
                'records' => []       // 本地 record_id 用來更新狀態
            ];
        }
        
        // ★ 修改 2：根據 status 自動分類
        // 如果 status 是 1，代表要「補點名」
        // 如果 status 是 NULL 或 0，代表要「補取消」
        if ($row['status'] == 1) {
            $batches[$key]['add_ids'][] = $row['member_id'];
        } else {
            $batches[$key]['del_ids'][] = $row['member_id'];
        }
        
        // 收集 record_id，不管成功失敗都要用到
        $batches[$key]['records'][] = $row['record_id'];
    }

    logger("共打包成 " . count($batches) . " 批次請求。");

    // 3. 逐批發送
    $url = CENTRAL_BASE_URL . "/edit_member_activity.php";

    foreach ($batches as $key => $batch) {
        $batchSuccess = true; // 預設成功，只要有一個步驟失敗就算失敗

        // --- 處理「補出席」 (Attend = 1) ---
        if (!empty($batch['add_ids'])) {
            $count = count($batch['add_ids']);
            logger("批次 {$key} (出席): 處理 {$count} 人...");
            
            $postData = [
                'meeting'    => $batch['item_id'],
                'year'       => $batch['year'],
                'week'       => $batch['week'],
                'attend'     => 1, // 設為出席
                'member_ids' => $batch['add_ids']
            ];

            // 呼叫 API
            if (!$service->sendToCentral($url, $postData, $cookieFile)) {
                $batchSuccess = false;
                logger("❌ 批次 {$key} (出席) 失敗。");
            }
        }

        // --- 處理「補取消」 (Attend = 0) ---
        if (!empty($batch['del_ids'])) {
            $count = count($batch['del_ids']);
            logger("批次 {$key} (取消): 處理 {$count} 人...");
            
            $postData = [
                'meeting'    => $batch['item_id'],
                'year'       => $batch['year'],
                'week'       => $batch['week'],
                'attend'     => 0, // ★ 設為缺席 (中央系統通常接受 0 或 delete 動作)
                'member_ids' => $batch['del_ids']
            ];

            // 呼叫 API
            if (!$service->sendToCentral($url, $postData, $cookieFile)) {
                $batchSuccess = false;
                logger("❌ 批次 {$key} (取消) 失敗。");
            }
        }

        // 4. 更新本地狀態
        $placeholders = implode(',', array_fill(0, count($batch['records']), '?'));
        
        if ($batchSuccess) {
            // ✅ 兩邊都成功，才把這批資料標記為已同步
            $updateSql = "UPDATE attendance_records SET synced=1, synced_at=NOW(), last_sync_error=NULL 
                          WHERE record_id IN ($placeholders)";
            $db->prepare($updateSql)->execute($batch['records']);
            logger("✅ 批次 {$key} 全部同步成功！");
        } else {
            // ❌ 只要有失敗，就整批標記失敗，下次重試
            $updateSql = "UPDATE attendance_records SET last_sync_error='批次同步部分失敗' 
                          WHERE record_id IN ($placeholders)";
            $db->prepare($updateSql)->execute($batch['records']);
        }

        // 避免太頻繁請求
        sleep(rand(2, 4));
    }

    logger("巡邏結束。");

} catch (Exception $e) {
    logger("🔥 發生致命錯誤: " . $e->getMessage());
}