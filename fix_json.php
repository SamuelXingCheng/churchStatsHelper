<?php
// fix_json.php
// 用來診斷並修復 credentials.json 的格式問題

$file = __DIR__ . '/credentials.json';

if (!file_exists($file)) {
    die("❌ 找不到 credentials.json，請確認檔案在當前目錄。\n");
}

// 讀取原始內容
$content = file_get_contents($file);
$originalLen = strlen($content);

echo "檢查檔案: credentials.json (大小: {$originalLen} bytes)\n";

// 1. 檢查 BOM (Byte Order Mark)
$bom = pack('H*', 'EFBBBF');
if (substr($content, 0, 3) === $bom) {
    echo "⚠️ 發現 BOM (隱藏字元)！這就是導致錯誤的元兇。\n";
    $content = substr($content, 3); // 移除前3個字元
    echo "✅ 已移除 BOM。\n";
} else {
    echo "✅ 沒有發現 BOM。\n";
}

// 2. 清理前後空白與不可見字元
$cleanContent = trim($content);
if ($cleanContent !== $content) {
    echo "⚠️ 發現前後有多餘空白或換行，正在清理...\n";
    $content = $cleanContent;
}

// 3. 嘗試解析 JSON
$data = json_decode($content, true);

if (json_last_error() === JSON_ERROR_NONE) {
    echo "🎉 JSON 格式驗證成功！\n";
    echo "Project ID: " . ($data['project_id'] ?? '未讀取到') . "\n";
    
    // 4. 覆蓋存檔 (確保是乾淨的 UTF-8 無 BOM)
    file_put_contents($file, $content);
    echo "💾 已將修復後的內容寫回 credentials.json。\n";
    echo "➡️ 現在請再次執行 'php test_google.php' 應該就會通過了！\n";
} else {
    echo "❌ JSON 依然無效。錯誤訊息: " . json_last_error_msg() . "\n";
    echo "--- 檔案內容開頭 (前 50 字元) ---\n";
    var_dump(substr($content, 0, 50));
    echo "---------------------------------\n";
    echo "建議：直接刪除該檔案，重新用 'nano credentials.json' 貼上內容。\n";
}
?>