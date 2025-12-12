<?php
// src/StatsService.php
require_once __DIR__ . '/Database.php';

class StatsService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * 修正重點：
     * 1. $groupId 允許為 null (前面加 ?)
     * 2. $userId 允許為 null (前面加 ?)
     */
    public function saveReports(?string $groupId, ?string $userId, array $reports, string $rawText) {
        $count = 0;
        
        // 確保這張表 weekly_stats 已經在資料庫建立
        $sql = "INSERT INTO weekly_stats 
                (line_group_id, line_user_id, report_week, main_district, sub_district, saint_count, gospel_count, new_count, raw_input) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);

        foreach ($reports as $row) {
            // 簡易防呆：如果沒有小區名稱就跳過
            if (empty($row['sub_district'])) continue;

            $stmt->execute([
                $groupId, // 這裡即使是 NULL，PDO 也能正確寫入資料庫
                $userId,
                $row['week'] ?? '',
                $row['main_district'] ?? '',
                $row['sub_district'],
                (int)($row['saint'] ?? 0),
                (int)($row['gospel'] ?? 0),
                (int)($row['new'] ?? 0),
                $rawText
            ]);
            $count++;
        }
        return $count;
    }
}
?>