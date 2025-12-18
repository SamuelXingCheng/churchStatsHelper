<?php

// 【新增】解除執行時間限制 (0 代表無限)
set_time_limit(0); 
// 【新增】增加記憶體上限 (避免資料量太大爆記憶體)
ini_set('memory_limit', '512M');

// backfill.php - 補抓歷史資料專用腳本
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/Database.php";
require_once __DIR__ . "/src/CentralSyncService.php";

// ================= 設定區 =================
// 1. 請輸入您要補抓的小區名稱 (必須與 config.php 裡的 DISTRICT_ID 對應)
$targetDistrictName = "永和"; 

// 2. 設定要補抓的範圍 (2025年)
$startWeek = 49; // 9月初約為第 35-36 週
$endWeek   = 51; // 目前約為第 51 週
$year      = 2025;
// ==========================================

header('Content-Type: text/plain; charset=utf-8');

// 1. 取得小區 ID
$districtMap = defined('DISTRICT_ID') ? DISTRICT_ID : [];
$churchId = $districtMap[$targetDistrictName] ?? '';

if (empty($churchId)) {
    die("錯誤：找不到小區 [$targetDistrictName] 的 ID 設定，請檢查 config.php\n");
}

echo "準備開始補抓資料...\n";
echo "目標小區：$targetDistrictName (ID: $churchId)\n";
echo "範圍：{$year}年 第 {$startWeek} 週 ~ 第 {$endWeek} 週\n";
echo "------------------------------------------------\n";

// 2. 檢查 Cookie
$cookieFile = __DIR__ . "/cookie/central_cookie.tmp";
if (!file_exists($cookieFile)) {
    die("錯誤：找不到 Cookie 檔案 ($cookieFile)。\n請先在網頁版/APP 執行一次「立即連線」或登入動作，產生 Cookie 後再執行此程式。\n");
}

$syncService = new CentralSyncService();

// 3. 開始迴圈
for ($w = $startWeek; $w <= $endWeek; $w++) {
    // 補零，例如 5 -> 05
    $weekStr = sprintf("%02d", $w);
    
    echo "正在處理：第 {$w} 週 ... ";

    // 組裝 URL
    $url = CENTRAL_BASE_URL . "/list_members.php"
         . "?start=0&limit=2000&year=$year&week=$weekStr" 
         . "&sex=&member_status=&status=&role="        
         . "&search_col=member_name&search=" 
         . "&churches%5B%5D=" . urlencode($churchId) 
         . "&filter_mode=churchStructureTab&roll_call_list="; 

    // 發送 CURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $result = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($info['http_code'] !== 200) {
        echo "[失敗] HTTP 代碼: " . $info['http_code'] . "\n";
        continue;
    }

    // 檢查是否 session 過期
    if (strpos($result, 'login.php') !== false) {
        die("\n[錯誤] Cookie 失效，中央系統要求重新登入。請重新登入後再試。\n");
    }

    $data = json_decode($result, true);
    if (!$data || !isset($data['members'])) {
        echo "[略過] 解析 JSON 失敗或無資料\n";
        continue;
    }

    // 呼叫同步 (傳入指定的 Year 和 Week)
    try {
        $syncService->syncMembersAndAttendance($targetDistrictName, $data, $year, $weekStr);
        echo "[成功] 同步 " . count($data['members']) . " 筆成員資料\n";
    } catch (Exception $e) {
        echo "[資料庫錯誤] " . $e->getMessage() . "\n";
    }

    // 稍微休息一下，避免對中央系統造成太大負擔
    usleep(200000); // 0.2秒
}

echo "------------------------------------------------\n";
echo "全部完成！請回到前端畫面重新載入名單。\n";
?>