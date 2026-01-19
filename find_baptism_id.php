<?php
// find_baptism_id.php
// 用途：分析系統主頁面 (HTML)，找出「受浸」對應的 Checkbox ID

header('Content-Type: text/plain; charset=utf-8');

// ================= 設定區 =================

// 1. Cookie 檔案路徑 (沿用您成功的設定)
$cookieFilePath = __DIR__ . '/cookie/central_cookie.tmp';

// 2. 目標網址：請注意這裡不能是 list_members.php (那是純資料)
// 通常選單會在登入後的第一個首頁，或是成員管理頁
// 請依序嘗試以下幾個網址，直到成功為止：
$targetUrl = "https://www.chlife-stat.org/index.php"; 
// 如果上面那個沒抓到，請試試看： "https://www.chlife-stat.org/members.php";

// ==========================================

echo "正在讀取頁面 HTML：$targetUrl ...\n";

if (!file_exists($cookieFilePath)) {
    die("❌ 找不到 Cookie 檔案。\n");
}

$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFilePath);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
// 模擬瀏覽器，不加 AJAX header，這樣伺服器才會給我們 HTML
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode != 200) {
    die("❌ 連線失敗 (HTTP $httpCode)\n");
}

// 檢查是否是 HTML
if (strpos($html, '<html') === false && strpos($html, '<body') === false) {
    echo "⚠️ 警告：回傳的內容看起來不像 HTML 頁面。\n";
    echo "前 200 字元：\n" . substr($html, 0, 200) . "\n";
    exit;
}

echo "✅ 成功取得 HTML 頁面，正在搜尋「受浸」相關欄位...\n";
echo str_repeat("-", 40) . "\n";

// 使用正規表示式搜尋 (Regex)
// 尋找邏輯：找到中文關鍵字，然後往前找最近的一個 value="數字"

$keywords = ["受浸", "受浸日期", "受浸地", "Baptism"];
$foundCount = 0;

foreach ($keywords as $keyword) {
    // 搜尋模式 1: Checkbox 寫法 <input value="123"> ... 受浸
    // 搜尋模式 2: Javascript 設定檔 {id:123, name:'受浸'}
    
    // 抓取關鍵字前後 100 個字元的內容來分析
    preg_match_all('/.{0,100}'.$keyword.'.{0,100}/u', $html, $matches);
    
    foreach ($matches[0] as $context) {
        // 嘗試從這段文字中提取 value="數字" 或 id="數字"
        if (preg_match('/value=["\'](\d+)["\']/i', $context, $idMatch) || 
            preg_match('/id["\']\s*:\s*["\']?(\d+)["\']?/i', $context, $idMatch)) {
            
            $id = $idMatch[1];
            // 過濾掉明顯不是的 ID (例如 0 或 1)
            if ($id > 10) { 
                echo "👉 發現可能目標！\n";
                echo "   關鍵字: [$keyword]\n";
                echo "   抓取 ID: $id\n";
                echo "   原始片段: " . strip_tags($context) . "\n";
                echo "----------------------------------------\n";
                $foundCount++;
            }
        }
    }
}

if ($foundCount == 0) {
    echo "❌ 自動搜尋失敗。程式沒在 HTML 裡找到受浸的 ID。\n";
    echo "原因可能是：\n";
    echo "1. 目標網址 $targetUrl 不是有點名表選單的頁面。\n";
    echo "2. 該選單是用複雜的 JavaScript 動態產生的。\n";
    echo "\n建議：請直接使用瀏覽器 F12 檢查元素查看。\n";
} else {
    echo "\n🎉 請嘗試將上面找到的 ID 加入您的 debug_baptism_file.php 中的 \$roll_call_list\n";
    echo "例如：...2483, 找到的ID\n";
}
?>