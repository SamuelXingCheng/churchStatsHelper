<?php
// cron_push_reminder.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/LineService.php';

try {
    $db = Database::getInstance()->getConnection();
    $line = new LineService();

    // 1. 從過往紀錄中找出所有不重複的群組 ID
    $stmt = $db->query("SELECT DISTINCT line_group_id FROM processing_queue WHERE line_group_id IS NOT NULL AND line_group_id != ''");
    $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($groups)) {
        echo "No groups found.\n";
        exit;
    }

    // 2. 定義 Flex Message 內容
    $flexContents = [
        "type" => "bubble",
        "body" => [
            "type" => "box",
            "layout" => "vertical",
            "contents" => [
                [
                    "type" => "text",
                    "text" => "兒童排回報提醒",
                    "weight" => "bold",
                    "color" => "#1DB446",
                    "size" => "sm"
                ],
                [
                    "type" => "text",
                    "text" => "親愛的弟兄姊妹們，大家這週的兒童排聚的如何？",
                    "weight" => "bold",
                    "size" => "md",
                    "margin" => "md",
                    "wrap" => true
                ],
                [
                    "type" => "text",
                    "text" => "兒童排實在是照顧並維繫下一代，最紮實的路。願我們持續勞苦並往前。\n\n麻煩大家回報這週的兒童排人數喔～",
                    "size" => "sm",
                    "color" => "#666666",
                    "wrap" => true,
                    "margin" => "md"
                ]
            ]
        ],
        "footer" => [
            "type" => "box",
            "layout" => "vertical",
            "contents" => [
                [
                    "type" => "button",
                    "action" => [
                        "type" => "message",
                        "label" => "立即回報人數",
                        "text" => "兒童排統計"
                    ],
                    "style" => "primary",
                    "color" => "#1DB446"
                ]
            ]
        ]
    ];

    // 3. 逐一發送給各個群組
    foreach ($groups as $groupId) {
        $line->pushFlexMessage($groupId, "兒童排回報提醒", $flexContents);
        // 延遲 0.2 秒避免觸發 LINE API 頻率限制
        usleep(200000); 
    }

    echo "Successfully pushed to " . count($groups) . " groups.\n";

} catch (Exception $e) {
    error_log("[Cron Error] " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}