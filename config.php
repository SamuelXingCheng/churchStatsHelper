<?php
// config.php
// 載入 .env 的簡易實作 (或使用 vlucas/phpdotenv)
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}
loadEnv(__DIR__ . '/.env');

// 定義常數
define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

define('LINE_CHANNEL_ACCESS_TOKEN', getenv('LINE_CHANNEL_ACCESS_TOKEN'));
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET'));
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY'));
define('GEMINI_MODEL', 'gemini-2.0-flash'); // 使用快速模型
?>