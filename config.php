<?php
// config.php - 最終修復版
header("Content-Type:text/html; charset=utf-8");

// === 1. 防止重複定義的 Helper 函數 ===
if (!function_exists('safe_define')) {
    function safe_define($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

// === 2. 載入 .env 檔案 ===
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \n\r\t\v\x00\"'"); 

        $_ENV[$name] = $value;
        putenv("{$name}={$value}"); 
    }
}
// 嘗試載入同一目錄下的 .env
loadEnv(__DIR__ . '/.env');

// 讀取函數 (優先讀取環境變數)
function getEnvValue($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?? $default;
}

// === 3. 資料庫與 API 設定 (優先讀取 .env) ===
safe_define('DB_HOST', getEnvValue('DB_HOST', 'localhost'));
safe_define('DB_NAME', getEnvValue('DB_NAME', 'church_db')); // 建議給個預設值
safe_define('DB_USER', getEnvValue('DB_USER', 'root'));
safe_define('DB_PASS', getEnvValue('DB_PASS', ''));

safe_define('LINE_CHANNEL_ACCESS_TOKEN', getEnvValue('LINE_CHANNEL_ACCESS_TOKEN'));
safe_define('LINE_CHANNEL_SECRET', getEnvValue('LINE_CHANNEL_SECRET'));
safe_define('GEMINI_API_KEY', getEnvValue('GEMINI_API_KEY'));
safe_define('GEMINI_MODEL', 'gemini-2.0-flash'); 

// Google Sheets 路徑處理
$credentialsPath = getEnvValue('GOOGLE_APPLICATION_CREDENTIALS');
if ($credentialsPath) {
    safe_define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/' . $credentialsPath);
} else {
    safe_define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/__dummy_credentials_path__');
}

safe_define('SPREADSHEET_ID', getEnvValue('SPREADSHEET_ID'));
safe_define('SPREADSHEET_TAB_NAME', getEnvValue('SPREADSHEET_TAB_NAME'));


// === 4. 點名系統設定 (合併 Env 與 硬編碼預設值) ===
// 邏輯：如果有設 .env 就用 .env，沒有就用後面的預設值

safe_define('CHURCHGROUP', getEnvValue('CHURCHGROUP', '5'));       // 台中區
safe_define('CHURCHID',    getEnvValue('CHURCHID', '68'));

// 帳號密碼 (同時支援新舊變數名稱，避免程式改壞)
$centralUser = getEnvValue('CENTRAL_USERNAME', 'sschen');
$centralPass = getEnvValue('CENTRAL_PASSWORD', 'peace2012');

safe_define('CENTRAL_USERNAME', $centralUser);
safe_define('CENTRAL_PASSWORD', $centralPass);
safe_define('ACCOUNT', $centralUser); // 相容舊程式
safe_define('PWD', $centralPass);     // 相容舊程式


// === 5. 其他靜態設定 ===
safe_define("GROUP", "三十九大區");
safe_define("TITLE", "台中市召會39大區");
safe_define("PASSWORD", 1239);

safe_define('go_home_MEETING', '38');   // 家聚會
safe_define('home_MEETING', '2312');
safe_define('smallGroup', '39');        // 排聚會
safe_define('Gospel', '1473');          // 福音出訪
safe_define('Revival', '2026');         // 晨興
safe_define('Lordsday', '37');          // 主日
safe_define('Pray', '40');              // 禱告
safe_define('ChildrenGroup', '768');    // 兒童排
safe_define('LifeStudy', '2483');       // 生命讀經

// 陣列設定
safe_define('DISTRICT_LIST', array("建成", "永和", "國光","大智"));
safe_define('Pray_GOAL_LIST', array("", "", ""));
safe_define('go_home_MEETING_GOAL_LIST', array("", "", "", ""));
safe_define('home_MEETING_GOAL_LIST', array("", "", "", ""));
safe_define('Gospel_GOAL_LIST', array("", "", "", ""));
safe_define('Revival_GOAL_LIST', array("", "", "", ""));
safe_define('smallGroup_GOAL_LIST', array("", "", "", ""));
safe_define('Lordsday_GOAL_LIST', array("", "", "", ""));
safe_define('ChildrenGroup_GOAL_LIST', array("", "", "", ""));
safe_define('LifeStudy_GOAL_LIST', array("", "", "", ""));

// === 6. 大區 ID 對照表 (維持逗號格式，程式端已做處理) ===
safe_define('DISTRICT_ID', array(
    '建成' => '7585,3',
    '永和' => '7586,3',
    '國光' => '7587,3',
    '大智' => '8792,3'
));

?>