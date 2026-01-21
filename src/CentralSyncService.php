<?php
// src/CentralSyncService.php
require_once __DIR__ . "/Database.php";
require_once __DIR__ . "/../config.php";

class CentralSyncService {
    private $conn;

    public function __construct() {
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

    /**
     * 同步成員與點名資料
     * @param bool $forceMode 強制以中央為準 (覆蓋本地黃燈)
     * @param int $skipSeconds 略過 N 秒內已同步的資料 (0 代表不略過)
     */
    public function syncMembersAndAttendance($district, $data, $targetYear = null, $targetWeek = null, $forceMode = false, $skipSeconds = 600) {
        if (!$data || !isset($data['members']) || !isset($data['meetingIds'])) {
            return;
        }
    
        $members    = $data['members'];
        $meetingIds = $data['meetingIds'];
        
        $districtMap  = defined('DISTRICT_ID') ? DISTRICT_ID : [];

        // 判斷日期
        if ($targetYear && $targetWeek) {
            $year = $targetYear;
            $week = $targetWeek;
            $dt = new DateTime();
            $dt->setISODate($year, $week); 
            $dt->modify('+6 days');       
            $date = $dt->format('Y-m-d');
        } else {
            $today = date("Y-m-d");
            $date  = $this->getSundayOfWeek($today);
            [$year, $week] = $this->calculateYearWeek($today);
        }

        try {
            // =========================================================
            // 🚀 優化 Step 0: 建立「近期已同步名單」快照
            // =========================================================
            $recentlySynced = [];
            
            if ($skipSeconds > 0) {
                // 撈出：同一年週、已同步(1)、且更新時間在 N 秒內的紀錄
                // 我們只需要 member_id，這樣 PHP 迴圈比對最快
                $checkSql = "SELECT DISTINCT member_id FROM attendance_records 
                             WHERE date = ? AND synced = 1 
                             AND synced_at > (NOW() - INTERVAL ? SECOND)";
                
                $stmtCheck = $this->conn->prepare($checkSql);
                $stmtCheck->execute([$date, $skipSeconds]);
                
                // 轉成 [member_id => true] 的格式，方便快速 lookup
                while ($row = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                    $recentlySynced[$row['member_id']] = true;
                }
            }

            // 1. 寫入 rollcall_items (項目很少，不需跳過)
            foreach ($meetingIds as $meetingId) {
                $sql = "INSERT INTO rollcall_items (item_id, name) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE name=VALUES(name)";
                $this->conn->prepare($sql)->execute([intval($meetingId), "中央項目 {$meetingId}"]);
            }

            // 2. 處理成員與點名
            $skippedCount = 0;
            $processedCount = 0;

            foreach ($members as $m) {
                $memberId = intval($m['member_id']);

                // ★★★ 關鍵優化：如果在快照名單內，直接跳過 ★★★
                if (isset($recentlySynced[$memberId])) {
                    $skippedCount++;
                    continue; 
                }

                $processedCount++;

                // -- 以下邏輯與之前相同，執行實際寫入 --

                $pathParts = isset($m['path']) ? explode(',', $m['path']) : [];
                $memberDistId = isset($pathParts[0]) ? intval($pathParts[0]) : 0;
                $groupId      = isset($pathParts[1]) ? intval($pathParts[1]) : null;
                $regionId     = isset($pathParts[2]) ? intval($pathParts[2]) : null;

                $name     = $m['member_name'] ?? '';
                $name     = preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<br/>', '<br>', '<BR/>', '<BR>'], ' ', $name)));
                $gender   = $m['sex'] ?? null;

                // 2.1 更新成員資料
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

                // 2.2 更新點名資料
                foreach ($meetingIds as $idx => $meetingId) {
                    $status = $m["attend{$idx}"] ?? null;
                    
                    // ★★★ 新增這行：強制轉為整數 (0 或 1) ★★★
                    $forceModeInt = (int)$forceMode; 

                    if ($forceMode) {
                        $newStatusExpr = "VALUES(status)";
                    } else {
                        $newStatusExpr = "IF(attendance_records.synced = 0, attendance_records.status, VALUES(status))";
                    }

                    // ★★★ 下面 SQL 中的 $forceMode 都要改成 $forceModeInt ★★★
                    $sql = "INSERT INTO attendance_records 
                                (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, created_at, synced, synced_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE 
                                status = $newStatusExpr,
                                synced = IF($forceModeInt = 0 AND attendance_records.synced = 0, 0, 1),
                                updated_at = IF(
                                    (attendance_records.status != VALUES(status)) OR (attendance_records.synced = 0),
                                    NOW(),
                                    attendance_records.updated_at
                                ),
                                synced_at = IF(
                                    ($forceModeInt = 1) OR (attendance_records.synced = 0),
                                    NOW(),
                                    attendance_records.synced_at
                                )";

                    $this->conn->prepare($sql)->execute([
                        $memberId, intval($meetingId), $date, $year, $week, $status, $memberDistId, $groupId, $regionId, $m['category'] ?? null
                    ]);
                }
            }
            
            // 可以在這裡 log 處理數量，方便除錯
            // error_log("[CentralSync] District: $district, Skipped: $skippedCount, Processed: $processedCount");
        
        } catch (\PDOException $e) {
            throw new \Exception("資料庫寫入失敗: " . $e->getMessage());
        }
    }
}
?>