<?php
// check_db_status.php (PHP 7.4 相容版)
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/Database.php";

header('Content-Type: text/plain; charset=utf-8');

$conn = Database::getInstance()->getConnection();

echo "🔍 資料庫數據檢查報告\n";
echo "========================================\n";

// 1. 檢查每個聚會類型的出席人數統計
$sql = "SELECT item_id, 
               COUNT(*) as total_records,
               SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as present_count,
               SUM(CASE WHEN status IS NULL OR status = 0 THEN 1 ELSE 0 END) as absent_count
        FROM attendance_records
        GROUP BY item_id
        ORDER BY item_id ASC";

$stmt = $conn->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) === 0) {
    echo "⚠️ 資料庫 attendance_records 表格是空的！\n";
} else {
    // 定義欄位寬度，方便對齊
    echo str_pad("項目ID", 12) . str_pad("總筆數", 10) . str_pad("✅已出席", 10) . str_pad("⬜未出席", 10) . "\n";
    echo str_repeat("-", 45) . "\n";
    
    // 定義名稱對照表 (替代 match)
    $meetingNames = [
        37 => '主日',
        38 => '家聚會',
        39 => '小排'
    ];

    foreach ($rows as $r) {
        $itemId = intval($r['item_id']);
        // 取得名稱，若無則顯示 "項目XXX"
        $name = isset($meetingNames[$itemId]) ? $meetingNames[$itemId] : '項目' . $itemId;
        
        echo str_pad($itemId . "($name)", 14) 
           . str_pad($r['total_records'], 10) 
           . str_pad($r['present_count'], 10) 
           . str_pad($r['absent_count'], 10) . "\n";
    }
}

echo "\n========================================\n";
echo "💡 說明：\n";
echo "1. 請檢查 [37(主日)] 這一列，'✅已出席' 應該要有數字。\n";
echo "2. 如果 [38(家聚會)] 的 '✅已出席' 是 0，代表大家都沒去家聚會，這是正常的。\n";
?>