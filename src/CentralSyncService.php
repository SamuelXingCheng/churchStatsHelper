<?php
// src/CentralSyncService.php
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/../config.php";

class CentralSyncService {
    private $conn;

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    // 輔助：從年份與週次算出該週主日(週日)的日期
    private function getSundayFromYearWeek($year, $week) {
        $dt = new DateTime();
        $dt->setISODate($year, $week); // 預設是該週週一
        $dt->modify('+6 days');        // 推算到週日
        return $dt->format('Y-m-d');
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

    /**
     * 主要修改：新增 $customYear 與 $customWeek 參數 (預設為 null)
     */
    public function syncMembersAndAttendance($district, $data, $customYear = null, $customWeek = null) {
        if (!$data || !isset($data['members']) || !isset($data['meetingIds'])) {
            return;
        }
    
        $members    = $data['members'];
        $meetingIds = $data['meetingIds'];
        
        $districtMap  = defined('DISTRICT_ID') ? DISTRICT_ID : [];
        $targetDistrictId = intval($districtMap[$district] ?? 0);

        // 【核心修正】判斷是否為補歷史資料
        if ($customYear && $customWeek) {
            // 如果有指定年份週次，就用指定的
            $year = $customYear;
            $week = $customWeek;
            $date = $this->getSundayFromYearWeek($year, $week);
        } else {
            // 否則維持原樣，抓今天
            $today = date("Y-m-d");
            $date  = $this->getSundayOfWeek($today);
            [$year, $week] = $this->calculateYearWeek($today);
        }

        try {
            // 1. 寫入 rollcall_items
            foreach ($meetingIds as $meetingId) {
                $sql = "INSERT INTO rollcall_items (item_id, name) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE name=VALUES(name)";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([intval($meetingId), "中央項目 {$meetingId}"]);
            }

            // 2. 處理成員與點名
            foreach ($members as $m) {
                $pathParts = isset($m['path']) ? explode(',', $m['path']) : [];
                $memberDistId = isset($pathParts[0]) ? intval($pathParts[0]) : 0;
                $groupId      = isset($pathParts[1]) ? intval($pathParts[1]) : null;
                $regionId     = isset($pathParts[2]) ? intval($pathParts[2]) : null;

                // 解析小區名稱
                $pathNameParts = isset($m['path_name']) ? explode(',', $m['path_name']) : [];
                $smallGroupName = $pathNameParts[2] ?? null; 
                if (empty($smallGroupName) && $regionId) {
                    $smallGroupName = "小組-" . $regionId;
                }

                $memberId = intval($m['member_id']);
                $name     = $m['member_name'] ?? '';
                $name     = preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<br/>', '<br>', '<BR/>', '<BR>'], ' ', $name)));
                $gender   = $m['sex'] ?? null;

                // Sync members
                $sql = "INSERT INTO members 
                            (member_id, name, gender, district_id, group_id, small_group_name, region_id, category, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            name=VALUES(name), gender=VALUES(gender), 
                            district_id=VALUES(district_id), group_id=VALUES(group_id),
                            small_group_name=VALUES(small_group_name), region_id=VALUES(region_id), 
                            category=VALUES(category), updated_at=NOW()";
                
                $this->conn->prepare($sql)->execute([
                    $memberId, $name, $gender, $memberDistId, $groupId, $smallGroupName, $regionId, $m['category'] ?? null
                ]);

                // Sync attendance
                foreach ($meetingIds as $idx => $meetingId) {
                    $status = $m["attend{$idx}"] ?? null;
                    $sql = "INSERT INTO attendance_records 
                                (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=NOW()";
                    $this->conn->prepare($sql)->execute([
                        $memberId, intval($meetingId), $date, $year, $week, $status, $memberDistId, $groupId, $regionId, $m['category'] ?? null
                    ]);
                }
            }
        
        } catch (\PDOException $e) {
            error_log("[CentralSyncService] DB Error: " . $e->getMessage());
            throw new \Exception("資料庫寫入失敗: " . $e->getMessage());
        }
    }
}
?>