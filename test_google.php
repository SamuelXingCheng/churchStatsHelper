<?php
// test_google.php
// 顯示所有錯誤，方便除錯
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "1. 載入檔案中...\n";
require_once __DIR__ . '/src/GoogleSheetsService.php';

try {
    echo "2. 初始化 Google 服務...\n";
    // 檢查 credentials.json 是否存在
    if (!file_exists(__DIR__ . '/credentials.json')) {
        die("❌ 錯誤：找不到 credentials.json 檔案！請確認它是否在根目錄。\n");
    }
    
    $sheetsService = new GoogleSheetsService();
    echo "✅ 服務初始化成功！\n";

    echo "3. 準備寫入測試資料...\n";
    $mockData = [
        [
            'week' => '測試週',
            'main_district' => '系統測試',
            'sub_district' => '連線測試排',
            'saint' => 1,
            'gospel' => 2,
            'new' => 3
        ]
    ];

    echo "4. 開始寫入...\n";
    $result = $sheetsService->appendStats($mockData);

    if ($result) {
        echo "🎉 寫入成功！\n";
        echo "請去 Google Sheet 的「" . SPREADSHEET_TAB_NAME . "」分頁查看，應該多了一行測試資料。\n";
        echo "API 回應: 更新了 " . $result->getUpdates()->getUpdatedCells() . " 格儲存格。\n";
    } else {
        echo "❌ 寫入失敗 (回傳 false)。請查看下方的 error_log 訊息。\n";
    }

} catch (Throwable $e) {
    echo "❌ 發生嚴重錯誤：\n";
    echo $e->getMessage() . "\n";
    echo "檔案: " . $e->getFile() . " 第 " . $e->getLine() . " 行\n";
}
?>