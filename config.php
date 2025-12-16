<?php
// config.php - 混合模式修復版 (優先讀 .env，失敗則用預設值)
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
loadEnv(__DIR__ . '/.env');

function getEnvValue($key, $default = null) {
    // 優先順序: $_ENV -> getenv() -> 預設值
    $val = $_ENV[$key] ?? getenv($key);
    return ($val !== false && $val !== null) ? $val : $default;
}
// error_log("開始讀取");
// === 3. 資料庫與 API 設定 (有預設值) ===
safe_define('DB_HOST', getEnvValue('DB_HOST', 'localhost'));
safe_define('DB_NAME', getEnvValue('DB_NAME', 'church_db')); 
safe_define('DB_USER', getEnvValue('DB_USER', 'root'));
safe_define('DB_PASS', getEnvValue('DB_PASS', ''));
safe_define('DB_CHARSET', getEnvValue('DB_CHARSET', 'utf8mb4'));
// error_log("DB_CHARSET: ".DB_CHARSET);

safe_define('LINE_CHANNEL_ACCESS_TOKEN', getEnvValue('LINE_CHANNEL_ACCESS_TOKEN'));
safe_define('LINE_CHANNEL_SECRET', getEnvValue('LINE_CHANNEL_SECRET'));
safe_define('GEMINI_API_KEY', getEnvValue('GEMINI_API_KEY'));
safe_define('GEMINI_MODEL', getEnvValue('GEMINI_MODEL', 'gemini-2.0-flash')); 
// error_log("LINE_CHANNEL_ACCESS_TOKEN: ".LINE_CHANNEL_ACCESS_TOKEN);
// error_log("LINE_CHANNEL_SECRET: ".LINE_CHANNEL_SECRET);
// error_log("GEMINI_API_KEY: ".GEMINI_API_KEY);
// error_log("GEMINI_MODEL: ".GEMINI_MODEL);

// Google Sheets 路徑
$credentialsPath = getEnvValue('GOOGLE_APPLICATION_CREDENTIALS');
if ($credentialsPath) {
    safe_define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/' . $credentialsPath);
} else {
    safe_define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/__dummy_credentials_path__');
}


safe_define('SPREADSHEET_ID', getEnvValue('SPREADSHEET_ID'));
safe_define('SPREADSHEET_TAB_NAME', getEnvValue('SPREADSHEET_TAB_NAME'));

// error_log("SPREADSHEET_ID: ".SPREADSHEET_ID);
// error_log("SPREADSHEET_TAB_NAME: ".SPREADSHEET_TAB_NAME);

// === 4. 點名系統設定 (有預設值) ===
safe_define('CHURCHGROUP', getEnvValue('CHURCHGROUP', '5'));    
// error_log("CHURCHGROUP: ".CHURCHGROUP); 
safe_define('CHURCHID',    getEnvValue('CHURCHID', '68'));
// error_log("CHURCHID: ".CHURCHID); 
safe_define('CENTRAL_BASE_URL', getEnvValue('CENTRAL_BASE_URL', 'https://www.chlife-stat.org'));
// error_log("CENTRAL_BASE_URL: ".CENTRAL_BASE_URL); 
$centralUser = getEnvValue('ACCOUNT', 'information');
$centralPass = getEnvValue('PWD', '123456');

safe_define('CENTRAL_USERNAME', $centralUser);
safe_define('CENTRAL_PASSWORD', $centralPass);
safe_define('ACCOUNT', $centralUser); 
safe_define('PWD', $centralPass);     
// error_log("CENTRAL_USERNAME: ".CENTRAL_USERNAME);
// error_log("CENTRAL_PASSWORD: ".CENTRAL_PASSWORD);
// error_log("ACCOUNT: ".ACCOUNT);
// error_log("PWD: ".PWD);


// === 5. 其他靜態設定 (有預設值) ===
safe_define("GROUP", getEnvValue('GROUP', ""));
safe_define("TITLE", getEnvValue('TITLE', ""));
// error_log("GROUP: ".GROUP);
// error_log("GROUP: ".GROUP);

// 聚會 ID (優先讀取 .env，沒有就用硬編碼)
safe_define('go_home_MEETING', getEnvValue('MEETING_GO_HOME', '38'));
safe_define('home_MEETING', getEnvValue('MEETING_HOME', '2312'));
safe_define('smallGroup', getEnvValue('MEETING_SMALL_GROUP', '39'));
safe_define('Gospel', getEnvValue('MEETING_GOSPEL', '1473'));
safe_define('Revival', getEnvValue('MEETING_REVIVAL', '2026'));
safe_define('Lordsday', getEnvValue('MEETING_LORDSDAY', '37'));
safe_define('Pray', getEnvValue('MEETING_PRAY', '40'));
safe_define('ChildrenGroup', getEnvValue('MEETING_CHILDREN_GROUP', '768'));
safe_define('LifeStudy', getEnvValue('MEETING_LIFE_STUDY', '2483'));

// 陣列設定 (保留預設值)
safe_define('DISTRICT_LIST', array("", "", "", ""));
safe_define('Pray_GOAL_LIST', array("", "", ""));
safe_define('go_home_MEETING_GOAL_LIST', array("", "", "", ""));
safe_define('home_MEETING_GOAL_LIST', array("", "", "", ""));
safe_define('Gospel_GOAL_LIST', array("", "", "", ""));
safe_define('Revival_GOAL_LIST', array("", "", "", ""));
safe_define('smallGroup_GOAL_LIST', array("", "", "", ""));
safe_define('Lordsday_GOAL_LIST', array("", "", "", ""));
safe_define('ChildrenGroup_GOAL_LIST', array("", "", "", ""));
safe_define('LifeStudy_GOAL_LIST', array("", "", "", ""));

// === 6. 大區 ID 對照表 (雙重保險機制) ===
$districtIdArray = [];
$districtMapsString = getEnvValue('DISTRICT_MAPS');

// 嘗試從 .env 解析
if (!empty($districtMapsString)) {
    $districtPairs = explode(';', $districtMapsString);
    foreach ($districtPairs as $pair) {
        $parts = explode(':', $pair, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $ids = trim($parts[1]);
            if (!empty($name) && !empty($ids)) {
                $districtIdArray[$name] = $ids;
            }
        }
    }
}
// error_log("[Config Debug] 永和 ID 讀取成功: " . $districtIdArray['永和']);

// 如果 .env 沒設定或解析失敗，使用硬編碼預設值
// if (empty($districtIdArray)) {
//     $districtIdArray = array(
//         '建成' => '7585,3',
//         '永和' => '7586,3',
//         '國光' => '7587,3',
//         '大智' => '8792,3'
//     );
// }

safe_define('DISTRICT_ID', $districtIdArray);


if (!empty($missing)) {
    // 只寫 Log，不讓網站掛掉，因為我們上方已經有預設值了
    error_log("[Config Warning] .env 可能未正確載入，缺少變數: " . implode(', ', $missing) . "。系統將使用預設值運行。");
}
?>