<?php
// config.php - 增強穩定版

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        // 清理值：移除開頭和結尾的空格或引號
        $value = trim($value, " \n\r\t\v\x00\"'"); 

        // 🟢 關鍵：同時寫入 $_ENV 和 putenv
        $_ENV[$name] = $value;
        putenv("{$name}={$value}"); 
    }
}
loadEnv(__DIR__ . '/.env');

// --- 讀取函數 (統一使用 $_ENV) ---
function getEnvValue($key, $default = null) {
    // 優先從 $_ENV 讀取，因為它是直接寫入的
    return $_ENV[$key] ?? $default;
}

// 定義常數
define('DB_HOST', getEnvValue('DB_HOST'));
define('DB_NAME', getEnvValue('DB_NAME'));
define('DB_USER', getEnvValue('DB_USER'));
define('DB_PASS', getEnvValue('DB_PASS'));

define('LINE_CHANNEL_ACCESS_TOKEN', getEnvValue('LINE_CHANNEL_ACCESS_TOKEN'));
define('LINE_CHANNEL_SECRET', getEnvValue('LINE_CHANNEL_SECRET'));
define('GEMINI_API_KEY', getEnvValue('GEMINI_API_KEY'));
define('GEMINI_MODEL', 'gemini-2.0-flash'); 

// 🟢 最終的 Google Sheets 路徑定義
$credentialsPath = getEnvValue('GOOGLE_APPLICATION_CREDENTIALS');
if ($credentialsPath) {
    define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/' . $credentialsPath);
} else {
    // 最終防線：如果 .env 讀不到，給一個假的，讓程式不要崩潰在 file_exists 上
    define('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/__dummy_credentials_path__');
}

define('SPREADSHEET_ID', getEnvValue('SPREADSHEET_ID'));
define('SPREADSHEET_TAB_NAME', getEnvValue('SPREADSHEET_TAB_NAME'));

// 確保其他常數 (如 CHURCHGROUP, CENTRAL_PASSWORD) 也能被定義
define('CHURCHGROUP', getEnvValue('CHURCHGROUP'));
define('CHURCHID', getEnvValue('CHURCHID'));
define('CENTRAL_USERNAME', getEnvValue('CENTRAL_USERNAME'));
define('CENTRAL_PASSWORD', getEnvValue('CENTRAL_PASSWORD'));
?>