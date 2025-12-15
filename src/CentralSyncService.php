<?php
// src/CentralSyncService.php
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/../config.php";

class CentralSyncService {
    private $conn;

    public function __construct() {
        // 使用 ChurchStatsHelper 的 Database 類別
        $this->conn = Database::getInstance()->getConnection();
    }

    private function calculateYearWeek($inputDate) {
        $ts       = strtotime($inputDate);
        $thursday = strtotime("thursday this week", $ts);
        $year     = date("Y", $thursday);
        $week     = date("W", $thursday);
        return [$year, $week];
    }

    private function getSundayOfWeek($inputDate) {
        $date = new DateTime($inputDate);
        $monday = clone $date;
        $monday->modify('Monday this week');
        $sunday = clone $monday;
        $sunday->modify('+6 days');
        return $sunday->format('Y-m-d');
    }

    public function syncMembersAndAttendance($district, $data) {
        if (!$data || !isset($data['members']) || !isset($data['meetingIds'])) {
            return;
        }

        $members    = $data['members'];
        $meetingIds = $data['meetingIds'];
        
        // 從 config.php 讀取 DISTRICT_ID (需確認 config.php 有定義，或在此處理 fallback)
        $districtMap = defined('DISTRICT_ID') ? DISTRICT_ID : [];
        $districtId = intval($districtMap[$district] ?? 0);

        $today = date("Y-m-d");
        $date  = $this->getSundayOfWeek($today);
        [$year, $week] = $this->calculateYearWeek($today);

        // 寫入 rollcall_items
        foreach ($meetingIds as $meetingId) {
            $sql = "INSERT IGNORE INTO rollcall_items (item_id, name) VALUES (?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([intval($meetingId), "中央項目 {$meetingId}"]);
        }

        // 處理成員與點名
        foreach ($members as $m) {
            $memberId = intval($m['member_id']);
            $name     = $m['member_name'] ?? '';
            $name     = preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<br/>', '<br>', '<BR/>', '<BR>'], ' ', $name)));
            $gender   = $m['sex'] ?? null;

            $pathParts = isset($m['path']) ? explode(',', $m['path']) : [];
            $distId    = isset($pathParts[0]) ? intval($pathParts[0]) : null;
            $groupId   = isset($pathParts[1]) ? intval($pathParts[1]) : null;
            $regionId  = isset($pathParts[2]) ? intval($pathParts[2]) : null;

            // Sync members
            $sql = "INSERT INTO members 
                        (member_id, name, gender, district_id, group_id, region_id, category, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        name=VALUES(name), gender=VALUES(gender), district_id=VALUES(district_id),
                        group_id=VALUES(group_id), region_id=VALUES(region_id), category=VALUES(category),
                        updated_at=NOW()";
            $this->conn->prepare($sql)->execute([
                $memberId, $name, $gender, $distId, $groupId, $regionId, $m['category'] ?? null
            ]);

            // Sync attendance
            foreach ($meetingIds as $idx => $meetingId) {
                $status = $m["attend{$idx}"] ?? null;
                $sql = "INSERT INTO attendance_records 
                            (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=NOW()";
                $this->conn->prepare($sql)->execute([
                    $memberId, intval($meetingId), $date, $year, $week, $status, $distId, $groupId, $regionId, $m['category'] ?? null
                ]);
            }
        }
    }
}
?>