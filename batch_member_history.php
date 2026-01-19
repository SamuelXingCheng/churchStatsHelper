<?php
// batch_member_history.php
// 【修改說明】此版本會強制寫入所有聚會項目，即使狀態為 NULL 也會保留，確保資料完整性。

set_time_limit(0); 
ini_set('memory_limit', '512M');

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/Database.php";

// ================= 配置區 =================
$csvFile = 'members_to_fetch.csv'; // CSV 檔名
$targetYear = 2025;                // 欲抓取的年份
$batchSize = 5;                   // 每次執行處理的人數
$batchName = "2025年度回溯任務";    // 任務標籤
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
// ==========================================

header('Content-Type: text/plain; charset=utf-8');

if (!file_exists($csvFile)) die("❌ 找不到 CSV 檔案\n");

$cookieFile = __DIR__ . "/cookie/central_cookie.tmp";
if (!file_exists($cookieFile)) die("❌ 請先登入中央系統以產生 Cookie。\n");

$db = Database::getInstance()->getConnection();
$districtMap = defined('DISTRICT_ID') ? DISTRICT_ID : [];

$handle = fopen($csvFile, "r");
fgetcsv($handle); // 跳過標頭

$currentIndex = 0;
$processedCount = 0;

echo "🚀 開始執行批量歷史資料抓取 (含 NULL 資料)\n------------------------------------------------\n";

while (($row = fgetcsv($handle)) !== FALSE) {
    if ($currentIndex < $offset) { $currentIndex++; continue; }
    if ($processedCount >= $batchSize) break;

    $name = trim($row[0]);
    $subDistrict = trim($row[2]);
    $churchId = $districtMap[$subDistrict] ?? '';

    if (empty($churchId)) {
        echo "⚠️ 跳過 [$name]: 找不到小區 [$subDistrict] 的設定。\n";
        $currentIndex++; continue;
    }

    echo "🔍 正在抓取：$name ($subDistrict) ... \n";

    // 逐週抓取 (1-52週)
    for ($w = 1; $w <= 52; $w++) {
        $weekStr = sprintf("%02d", $w);
        $url = CENTRAL_BASE_URL . "/list_members.php"
             . "?start=0&limit=10&year=$targetYear&week=$weekStr" 
             . "&search_col=member_name&search=" . urlencode($name)
             . "&churches%5B%5D=" . urlencode($churchId) 
             . "&filter_mode=churchStructureTab&roll_call_list="; 

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); 
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        
        // 確保有抓到成員資料
        if ($data && isset($data['members']) && count($data['members']) > 0) {
            $meetingIds = $data['meetingIds'] ?? [];
            
            foreach ($data['members'] as $m) {
                // 只針對 CSV 中指定的姓名進行精確匹配 (防止同名或搜尋誤差)
                if (trim($m['member_name']) !== $name) continue;

                // 計算該週主日日期
                $dt = new DateTime();
                $dt->setISODate($targetYear, $w);
                $dt->modify('+6 days');
                $sundayDate = $dt->format('Y-m-d');

                // 解析路徑 ID
                $pathParts = isset($m['path']) ? explode(',', $m['path']) : [];
                $memberDistId = isset($pathParts[0]) ? intval($pathParts[0]) : 0;
                $groupId      = isset($pathParts[1]) ? intval($pathParts[1]) : null;
                $regionId     = isset($pathParts[2]) ? intval($pathParts[2]) : null;

                // 遍歷所有聚會項目
                foreach ($meetingIds as $idx => $mid) {
                    // 取得狀態 (可能為 1, 0, 或 null)
                    $status = $m["attend{$idx}"] ?? null;
                    
                    // 【修正點】：移除這裡原本的 if ($status === null) continue;
                    // 現在即使是 null 也會往下執行寫入

                    $sql = "INSERT INTO historical_attendance 
                            (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, batch_name)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE status=VALUES(status)";
                    
                    $db->prepare($sql)->execute([
                        intval($m['member_id']), 
                        intval($mid), 
                        $sundayDate, 
                        $targetYear, 
                        $w, 
                        $status, // 這裡會正確傳入 NULL 到資料庫
                        $memberDistId, 
                        $groupId, 
                        $regionId, 
                        $m['category'], 
                        $batchName
                    ]);
                }
            }
        }
        usleep(50000); // 休息避免請求過快
    }
    $processedCount++; $currentIndex++;
    echo "✅ $name (一整年) 抓取完成。\n";
}
fclose($handle);

$nextOffset = $offset + $batchSize;
echo "------------------------------------------------\n🏁 本次處理完畢。下一批 Offset: $nextOffset\n";
?>