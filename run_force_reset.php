<?php
// run_force_reset.php (一次性救援程式)
// 用途：強制將本地資料庫更新為與中央一致 (無視本地黃燈)

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(0); 

require_once __DIR__ . '/src/AttendanceService.php';
require_once __DIR__ . '/src/CentralSyncService.php';
require_once __DIR__ . '/config.php';

echo "🚀 開始執行強制重置 (Force Reset)...\n";
echo "警告：此操作將會覆蓋本地所有未同步 (黃燈) 的資料，以中央為準。\n\n";

$svc = new AttendanceService();
$sync = new CentralSyncService();

// 取得所有設定的大區
$districts = defined('DISTRICT_ID') ? array_keys(DISTRICT_ID) : [];

if (empty($districts)) {
    die("❌ 錯誤：config.php 中未設定 DISTRICT_ID\n");
}

$cookieFile = __DIR__ . "/cookie/central_cookie.tmp";
if (!file_exists($cookieFile)) {
    die("❌ 錯誤：找不到 Cookie，請先去網頁端手動登入一次。\n");
}

foreach ($districts as $districtName) {
    echo "正在處理大區：[$districtName] ...\n";

    // 1. 取得資料 (借用 AttendanceService 的邏輯)
    // 我們需要手動呼叫 curl，因為 AttendanceService::centralMembers 會自動執行 sync (但不是強制模式)
    // 為了簡單起見，我們這裡直接複製 AttendanceService 的 fetch 邏輯的一小部分
    
    // 計算本週
    $year = date("o");
    $week = date("W");
    $churchId = DISTRICT_ID[$districtName] ?? '';

    $url = CENTRAL_BASE_URL . "/list_members.php"
            . "?start=0&limit=3000&year=$year&week=$week" 
            . "&churches%5B%5D=" . urlencode($churchId) 
            . "&filter_mode=churchStructureTab&roll_call_list=";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // 模仿 User-Agent
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 || !$result) {
        echo "❌ [失敗] 無法連線至中央 (HTTP $httpCode)\n";
        continue;
    }

    $data = json_decode($result, true);
    if (!$data || !isset($data['members'])) {
        echo "❌ [失敗] 中央回傳格式錯誤\n";
        continue;
    }

    $memberCount = count($data['members']);
    echo "📥 下載成功，共 {$memberCount} 筆成員資料。\n";

    // ★ 第 5 個參數 (forceMode) = true (強制覆蓋)
    // ★ 第 6 個參數 (skipSeconds) = 600 (跳過 10 分鐘內已處理過的)
    $sync->syncMembersAndAttendance($districtName, $data, $year, $week, true, 600);

    echo "✅ [$districtName] 處理完成！\n";
    echo "----------------------------------------\n";
    
    // 休息一下防封鎖
    sleep(1);
}

echo "\n🎉 所有區域處理完畢。現在您的資料庫已與中央完全一致。\n";