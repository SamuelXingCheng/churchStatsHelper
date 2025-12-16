<?php
// === 設定錯誤顯示 (開發階段開啟) ===
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === 1. 設定標頭 ===
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// === 2. 處理 OPTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// === 3. 除錯日誌 ===
$debugMsg = date('Y-m-d H:i:s') . " | Method: " . $_SERVER['REQUEST_METHOD'] . " | Path: " . ($_GET['path'] ?? 'None') . "\n";
file_put_contents('debug_log.txt', $debugMsg, FILE_APPEND);

// === 4. 獲取路由參數 ===
$path = isset($_GET['path']) ? trim($_GET['path']) : '';

try {
    // ★★★ 修正點：路徑指向 src/AttendanceService.php ★★★
    $servicePath = 'src/AttendanceService.php';

    if (!file_exists($servicePath)) {
        // 這裡會明確告訴你是不是路徑指錯了
        throw new Exception("找不到檔案：$servicePath (請確認 src 資料夾與 api.php 在同一層)", 500);
    }
    
    require_once $servicePath;

    // ★★★ 重要檢查：Namespace ★★★
    // 如果你的 AttendanceService.php 第一行有寫 "namespace App;" 或 "namespace Src;"
    // 下面的 new AttendanceService() 會失敗。
    // 如果失敗，請嘗試改成 new \Src\AttendanceService() 或 new \App\AttendanceService();
    
    if (!class_exists('AttendanceService')) {
        // 嘗試自動偵測是否有常見的 Namespace
        if (class_exists('Src\AttendanceService')) {
            $service = new Src\AttendanceService();
        } elseif (class_exists('App\AttendanceService')) {
            $service = new App\AttendanceService();
        } else {
            throw new Exception("載入了檔案但找不到類別 'AttendanceService'。請檢查該檔案是否有設定 namespace？", 500);
        }
    } else {
        // 沒有 namespace 的情況
        $service = new AttendanceService();
    }
    
    // 呼叫處理函式
    $result = $service->handleRequest($path);
    
    // 輸出結果
    if (is_array($result)) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        echo $result;
    }

} catch (Exception $e) {
    // === 5. 錯誤處理 ===
    $code = $e->getCode();
    if ($code < 100 || $code > 599) { $code = 500; }
    
    http_response_code($code);
    echo json_encode([
        "status" => "error",
        "code" => $code,
        "message" => $e->getMessage(),
        "path_received" => $path
    ], JSON_UNESCAPED_UNICODE);
}
?>