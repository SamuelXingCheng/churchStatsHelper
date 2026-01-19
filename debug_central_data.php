<?php
// debug_baptism_file.php
// 用途：使用伺服器上的 Cookie 檔案來抓取隱藏欄位

header('Content-Type: text/plain; charset=utf-8');

// ================= 設定區 =================

// 1. 指定 Cookie 檔案的絕對路徑
// __DIR__ 代表目前程式所在的目錄
$cookieFilePath = __DIR__ . '/cookie/central_cookie.tmp';

// 2. 設定目標
$searchName = "林信呈";

// 3. 設定召會 ID (從您之前的網址得知是 7586,3)
$churchIds = "7586,3";

// ==========================================

echo "【系統檢查】\n";
echo "正在檢查 Cookie 檔案：$cookieFilePath\n";

if (!file_exists($cookieFilePath)) {
    die("❌ 錯誤：找不到檔案！請確認 'cookie' 資料夾是否存在，且與此程式在同一層目錄。\n");
}

$fileTime = filemtime($cookieFilePath);
$fileAge = time() - $fileTime;
echo "✅ 檔案存在！\n";
echo "最後更新時間：" . date("Y-m-d H:i:s", $fileTime) . " (距今 $fileAge 秒)\n";

if ($fileAge > 3600) { // 如果超過1小時沒更新
    echo "⚠️ 警告：這個 Cookie 檔案已經超過 1 小時沒更新，Session 可能已經過期！\n";
    echo "建議：請先執行您的登入程式 (Login Script) 更新此檔案。\n";
}
echo str_repeat("-", 40) . "\n\n";

// ================= 開始抓取 =================

// 組合 URL
$baseUrl = "https://www.chlife-stat.org/list_members.php";
$params = [
    'start' => '0',
    'limit' => '50',
    'year' => '2025',
    'week' => '50',
    'search_col' => 'member_name',
    'search' => $searchName,
    'filter_mode' => 'churchStructureTab',
    'roll_call_list' => "37,40,2312,39,2026,1473,768,2483" 
];

// 手動組裝以確保 churches[] 格式正確
$queryString = http_build_query($params) . "&churches%5B%5D=" . $churchIds;
$fullUrl = $baseUrl . "?" . $queryString;

echo "正在查詢：$searchName ...\n";

$ch = curl_init($fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// ★★★ 關鍵：使用檔案作為 Cookie 來源 ★★★
// CURLOPT_COOKIEFILE 會解析 Netscape 格式的 Cookie 檔
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFilePath);

// 加入 AJAX 偽裝標頭 (增加成功率)
$headers = [
    "X-Requested-With: XMLHttpRequest",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
    "Accept: application/json"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ================= 解析結果 =================

if ($httpCode != 200) {
    die("❌ 連線失敗 (HTTP $httpCode)\n");
}

// 檢查是否被導回登入頁 (HTML)
if (strpos($response, '<!DOCTYPE html>') !== false) {
    echo "❌ 失敗：伺服器回傳 HTML 登入頁面。\n";
    echo "原因：雖然讀到了 Cookie 檔案，但裡面的 Session ID 已經失效。\n";
    echo "解決：請務必重新執行您的「登入程式」來更新 central_cookie.tmp。\n";
    exit;
}

$data = json_decode($response, true);

if (!$data) {
    echo "⚠️ 無法解析 JSON，原始回應：\n" . substr($response, 0, 300) . "\n";
    exit;
}

// 尋找資料
$membersList = [];
if (isset($data['rows'])) $membersList = $data['rows'];
elseif (isset($data['members'])) $membersList = $data['members'];
elseif (is_array($data)) $membersList = $data;

if (!empty($membersList)) {
    $member = $membersList[0];
    echo "✅ 成功取得資料！(姓名: " . $member['member_name'] . ")\n";
    echo str_repeat("=", 50) . "\n";
    
    foreach ($member as $k => $v) {
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        
        // 標記可能的欄位
        $mark = "";
        if (strpos($k, 'col_') === 0) $mark = " ★ 檢查這個隱藏欄位";
        if (preg_match('/(bapt|date)/i', $k)) $mark = " ★ 可能是受浸";
        
        echo "[$k] : $v$mark\n";
    }
} else {
    echo "⚠️ 查詢成功但找不到資料。請確認召會 ID ($churchIds) 是否正確。\n";
}
?>