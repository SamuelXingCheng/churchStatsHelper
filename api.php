<?php
// api.php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// 引入服務
require_once __DIR__ . '/src/AttendanceService.php';

$path = $_GET['path'] ?? '';

try {
    // 實例化服務
    $service = new AttendanceService();
    
    // 呼叫整合後的處理函式 (會根據 path 自動分配到對應邏輯)
    $result = $service->handleRequest($path);
    
    // 輸出結果
    if (is_array($result)) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        echo $result;
    }

} catch (Exception $e) {
    // 捕捉錯誤 (例如路徑不存在或邏輯錯誤)
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>