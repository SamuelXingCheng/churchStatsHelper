<?php
// debug_path.php
require_once __DIR__ . '/config.php';

echo "=== 環境變數診斷 ===\n";
echo "1. 專案根目錄 (__DIR__): " . __DIR__ . "\n";

// 測試讀取 .env
$envPath = __DIR__ . '/.env';
echo "2. .env 檔案位置: " . $envPath . "\n";
if (file_exists($envPath)) {
    echo "   ✅ .env 檔案存在\n";
    $content = file_get_contents($envPath);
    echo "   📄 .env 內容預覽 (前 100 字元): " . substr(str_replace(["\r", "\n"], ' ', $content), 0, 100) . "...\n";
} else {
    echo "   ❌ 找不到 .env 檔案！這就是問題原因。\n";
}

// 測試 getenv
$envValue = getenv('GOOGLE_APPLICATION_CREDENTIALS');
echo "3. getenv('GOOGLE_APPLICATION_CREDENTIALS'): [" . ($envValue ? $envValue : "❌ 空值/False") . "]\n";

// 測試最終常數
echo "4. 最終設定路徑 (GOOGLE_APPLICATION_CREDENTIALS): [" . GOOGLE_APPLICATION_CREDENTIALS . "]\n";

// 檢查該路徑
if (file_exists(GOOGLE_APPLICATION_CREDENTIALS)) {
    if (is_dir(GOOGLE_APPLICATION_CREDENTIALS)) {
        echo "   ⚠️ 嚴重警告：這個路徑是一個「資料夾」，不是檔案！\n";
        echo "      原因：getenv 回傳空值，導致路徑變成專案根目錄。\n";
    } else {
        echo "   ✅ 檔案存在且確認為檔案。\n";
    }
} else {
    echo "   ❌ 系統找不到此路徑的檔案。\n";
}
?>