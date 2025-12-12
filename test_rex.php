<?php
// 2. PHP 解析邏輯範例 (Parser)

$message = "
9/15-9/21
十七大區兒童排
聖徒兒童數：5
福音兒童數：0
新接觸三次以上：0

二三大區幼幼排
聖徒兒童：9
福音兒童：0
新接觸三次以上：0
"; 
// ... (你可以放入更多測試文字)

function parseStats($text) {
    $results = [];
    
    // 這個正則會抓取「名稱」跟後面跟著的三個數據
    // 支援「：」全形冒號和不同寫法 (如：聖徒兒童數 / 聖徒兒童)
    $pattern = '/(?P<name>[^\n]+)\n\s*聖徒.*?[：:]\s*(?P<saint>\d+)\s*\n\s*福音.*?[：:]\s*(?P<gospel>\d+)\s*\n\s*新接觸.*?[：:]\s*(?P<new>\d+)/u';

    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        // 排除掉日期行 (如果名稱是日期格式就跳過，簡單過濾)
        if (preg_match('/^\d+\/\d+/', trim($match['name']))) {
            continue;
        }

        $results[] = [
            'sub_district' => trim($match['name']),
            'saint' => (int)$match['saint'],
            'gospel' => (int)$match['gospel'],
            'new' => (int)$match['new']
        ];
    }
    return $results;
}

try {
    // 3. 資料庫連線與寫入 (記得改你的 DB 名稱和密碼)
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=church_stats;charset=utf8mb4', 'root', 'your_password');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 模擬資料：正式環境會從 LINE Webhook 抓 Group ID，日期也可以寫正則抓
    $groupId = 'Dummy_Group_123'; 
    $reportDate = date('Y-m-d'); 

    $stmt = $pdo->prepare("INSERT INTO weekly_stats (line_group_id, sub_district, saint_count, gospel_count, new_count, report_date, raw_text) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($data as $row) {
        $stmt->execute([
            $groupId, 
            $row['sub_district'], 
            $row['saint'], 
            $row['gospel'], 
            $row['new'],
            $reportDate,
            $message // 把原始訊息存起來備查，除錯很方便
        ]);
    }
    echo "成功寫入 " . count($data) . " 筆資料到 MySQL！\n";

} catch (PDOException $e) {
    echo "資料庫錯誤：" . $e->getMessage();
}

// 測試輸出
$data = parseStats($message);
print_r($data);
?>