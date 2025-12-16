<?php
// src/CentralSyncService.php
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/../config.php";

class CentralSyncService {
    private $conn;

    public function __construct() {
        // 假設您的 Database 類別和路徑是正確的
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
    
        // ★★★ 臨時除錯步驟：強制清空資料表，排除數據衝突 ★★★
        // 確保連線正確
        // $this->conn->exec("TRUNCATE TABLE members"); 
        // $this->conn->exec("TRUNCATE TABLE attendance_records");
        // rollcall_items 使用 INSERT IGNORE，可以不清除
    
        $members    = $data['members'];
        $meetingIds = $data['meetingIds'];
        
        // 取得目標大區的 ID
        $districtMap  = defined('DISTRICT_ID') ? DISTRICT_ID : [];
        $targetDistrictId = intval($districtMap[$district] ?? 0);

        $today = date("Y-m-d");
        $date  = $this->getSundayOfWeek($today);
        [$year, $week] = $this->calculateYearWeek($today);
        // error_log("districtMap:".$districtMap);
        // error_log("targetDistrictId:".$targetDistrictId);
        error_log("test:");
        // ★★★ 核心修正：加入 try...catch 捕捉所有資料庫錯誤 ★★★
        try {
            // 寫入 rollcall_items
            foreach ($meetingIds as $meetingId) {
                error_log("test1:");
                // 修正: 排除 item_id 欄位，讓 Primary Key 自動生成。
                // 如果您的 item_id 欄位是中央系統的 ID 且需要與中央 ID 一致，則必須關閉 auto_increment。
                // 假設 item_id 應作為中央 ID 寫入，且您在建立時手動關閉了 auto_increment：
                // 如果您在建立時是這樣設計的: Primary Key=item_id, 且沒有 auto_increment，則原代碼是對的。
                
                // 我們退回原代碼並假設您的資料庫設計是正確的，但欄位名稱可能需要調整。
                $sql = "INSERT INTO rollcall_items (item_id, name) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE name=VALUES(name)";
                        
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([intval($meetingId), "中央項目 {$meetingId}"]);
            }
            error_log("test2:");
            // 處理成員與點名
            foreach ($members as $m) {
                $pathParts = isset($m['path']) ? explode(',', $m['path']) : [];
                
                // 解析這位成員所屬的大區 ID
                $memberDistId = isset($pathParts[0]) ? intval($pathParts[0]) : 0;
                $groupId      = isset($pathParts[1]) ? intval($pathParts[1]) : null;
                $regionId     = isset($pathParts[2]) ? intval($pathParts[2]) : null;

                error_log("test3:");
                error_log("targetDistrictId:".$targetDistrictId);
                error_log("memberDistId:".$memberDistId);
                // 嚴格過濾
                // if ($targetDistrictId > 0 && $memberDistId !== $targetDistrictId) {
                //     continue; 
                // }
                error_log("test4:");
                $memberId = intval($m['member_id']);
                $name     = $m['member_name'] ?? '';
                $name     = preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<br/>', '<br>', '<BR/>', '<BR>'], ' ', $name)));
                $gender   = $m['sex'] ?? null;

                error_log("memberId:".$memberId);
                error_log("name:".$name);

                // Sync members
                $sql = "INSERT INTO members 
                            (member_id, name, gender, district_id, group_id, region_id, category, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            name=VALUES(name), gender=VALUES(gender), district_id=VALUES(district_id),
                            group_id=VALUES(group_id), region_id=VALUES(region_id), category=VALUES(category),
                            updated_at=NOW()";
                
                $this->conn->prepare($sql)->execute([
                    $memberId, $name, $gender, $memberDistId, $groupId, $regionId, $m['category'] ?? null
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
            // 拋出錯誤，強制讓上層 (AttendanceService::centralMembers) 捕捉並回傳 JSON 錯誤
            throw new \Exception("資料庫寫入失敗。請檢查表格結構、欄位是否存在。錯誤訊息: " . $e->getMessage());
        }
    }
}
?>