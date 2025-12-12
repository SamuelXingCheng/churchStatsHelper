<?php
// test_db.php

// 1. 引入您的 DB 連線設定 (模擬 config.php 或直接寫)
$host = '127.0.0.1';
$db   = 'church_stats';
$user = 'root';
$pass = '您的密碼'; // 請修改這裡
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    echo "正在嘗試連線資料庫...\n";
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "✅ 資料庫連線成功！\n";

    // 2. 準備一筆模擬的解析後資料 (這是剛剛 test_rex.php 跑出來的結果)
    $mockData = [
        [
            'sub_district' => '十七大區兒童排',
            'saint' => 5,
            'gospel' => 0,
            'new' => 0
        ],
        [
            'sub_district' => '二三大區幼幼排',
            'saint' => 9,
            'gospel' => 0,
            'new' => 0
        ]
    ];

    // 3. 執行寫入
    echo "正在寫入測試資料...\n";
    $stmt = $pdo->prepare("INSERT INTO weekly_stats (line_group_id, sub_district, saint_count, gospel_count, new_count, report_date, raw_text) VALUES (?, ?, ?, ?, ?, ?, ?)");

    $fakeGroupId = 'TEST_GROUP_001';
    $reportDate = date('Y-m-d');
    $rawText = '這是手動測試寫入';

    foreach ($mockData as $row) {
        $stmt->execute([
            $fakeGroupId,
            $row['sub_district'],
            $row['saint'],
            $row['gospel'],
            $row['new'],
            $reportDate,
            $rawText
        ]);
        echo "寫入成功: " . $row['sub_district'] . "\n";
    }

    echo "✅ 全部測試完成！請去資料庫檢查 weekly_stats 表。\n";

} catch (\PDOException $e) {
    echo "❌ 資料庫錯誤: " . $e->getMessage() . "\n";
    echo "請檢查帳號密碼或是資料表是否已建立。\n";
}
?>