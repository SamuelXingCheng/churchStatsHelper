<?php
// sync_runner.php
// 設定讓程式可以在背景跑久一點 (防止 PHP 30秒逾時)
set_time_limit(0); 
ini_set('memory_limit', '256M');

// 引入必要的檔案 (路徑請依您實際結構調整)
require_once __DIR__ . '/src/AttendanceService.php';

// 建立服務實體
$service = new AttendanceService();
$db = Database::getInstance()->getConnection();

echo "[" . date("Y-m-d H:i:s") . "] 啟動背景同步巡邏...\n";

// 1. 撈出 status=1 (已出席) 但 synced=0 (未同步) 的紀錄
// 限制 50 筆，避免一次處理太多導致伺服器負載過高
$sql = "SELECT * FROM attendance_records WHERE status = 1 AND synced = 0 LIMIT 50";
$stmt = $db->query($sql);
$pendingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingList)) {
    echo "目前無待同步紀錄。\n";
    exit;
}

echo "發現 " . count($pendingList) . " 筆待同步資料。\n";

foreach ($pendingList as $row) {
    echo "處理 Record ID: {$row['record_id']} (成員: {$row['member_id']}) ... ";

    // 準備要送給中央的資料格式
    $postData = [
        'meeting'    => $row['item_id'],
        'year'       => $row['year'],
        'week'       => $row['week'],
        'attend'     => 1, // 補點名一定是出席
        'member_ids' => [$row['member_id']]
    ];

    $url = CENTRAL_BASE_URL . "/edit_member_activity.php";
    
    // 注意：cookie 路徑要與 AttendanceService 內的一致
    $cookieFile = __DIR__ . "/cookie/central_cookie.tmp";

    // 2. 呼叫我們剛剛強化過的 sendToCentral
    // 由於 sendToCentral 是 private 方法，我們這裡有兩個選擇：
    // 方法 A: 將 AttendanceService 中的 sendToCentral 改為 public (最簡單)
    // 方法 B: 在這裡直接使用 curl 邏輯 (較繁瑣)
    
    // 這裡假設您已將 sendToCentral 改為 public，或者我們使用 Reflection 呼叫
    // 為求方便，建議您去 AttendanceService.php 把 sendToCentral 的 private 改成 public
    
    // 模擬呼叫 sendToCentral 的邏輯
    if (call_user_func([$service, 'sendToCentral'], $url, $postData, $cookieFile)) {
        
        // ✅ 成功：更新 synced = 1
        $update = $db->prepare("UPDATE attendance_records SET synced=1, synced_at=NOW(), last_sync_error=NULL WHERE record_id = ?");
        $update->execute([$row['record_id']]);
        echo "成功！\n";
        
    } else {
        
        // ❌ 失敗：紀錄錯誤，下次再試
        $update = $db->prepare("UPDATE attendance_records SET last_sync_error='背景同步失敗' WHERE record_id = ?");
        $update->execute([$row['record_id']]);
        echo "失敗。\n";
    }

    // 🛡️ 絕對防封鎖：每處理一筆，強制休息 2~4 秒
    // 這是背景程式最重要的部分，讓它慢慢跑，不要觸發中央防火牆
    sleep(rand(2, 4));
}

echo "巡邏結束。\n";