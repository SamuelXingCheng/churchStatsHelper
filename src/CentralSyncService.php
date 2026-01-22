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
     * @param mixed  $district     小區
     * @param array  $data         中央回傳的資料
     * @param int    $targetYear   指定年份
     * @param int    $targetWeek   指定週數
     * @param bool   $forceMode    強制覆蓋模式
     * @param int    $skipSeconds  略過 N 秒內已同步的資料 (0=手動同步不略過)
     * @param mixed  $limitToItem  [新增] 限制只處理特定聚會 ID (例如 37)，若為 null 則處理全部
     */
    public function syncMembersAndAttendance($district, $data, $targetYear = null, $targetWeek = null, $forceMode = false, $skipSeconds = 600, $limitToItem = null) {
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
            // 快照檢查 (若 skipSeconds > 0)
            // =========================================================
            $recentlySynced = [];
            
            if ($skipSeconds > 0) {
                // 如果有限制特定 ID，檢查時也要加上 item_id 條件，避免誤判
                $checkSql = "SELECT DISTINCT member_id FROM attendance_records 
                             WHERE date = ? AND synced = 1 
                             AND synced_at > (NOW() - INTERVAL ? SECOND)";
                
                // 如果有限制項目，我們只檢查該項目的同步狀態
                if ($limitToItem) {
                    $checkSql .= " AND item_id = " . intval($limitToItem);
                }

                $stmtCheck = $this->conn->prepare($checkSql);
                $stmtCheck->execute([$date, $skipSeconds]);
                
                while ($row = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                    $recentlySynced[$row['member_id']] = true;
                }
            }

            // 1. 寫入 rollcall_items
            foreach ($meetingIds as $meetingId) {
                // ★★★ 過濾點 1：如果是限制模式，且 ID 不符，則不建立項目 ★★★
                if ($limitToItem && intval($meetingId) !== intval($limitToItem)) {
                    continue;
                }

                $sql = "INSERT INTO rollcall_items (item_id, name) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE name=VALUES(name)";
                $this->conn->prepare($sql)->execute([intval($meetingId), "中央項目 {$meetingId}"]);
            }

            // 2. 處理成員與點名
            foreach ($members as $m) {
                $memberId = intval($m['member_id']);

                if (isset($recentlySynced[$memberId])) {
                    continue; 
                }

                $pathParts = isset($m['path']) ? explode(',', $m['path']) : [];
                $memberDistId = isset($pathParts[0]) ? intval($pathParts[0]) : 0;
                $groupId      = isset($pathParts[1]) ? intval($pathParts[1]) : null;
                $regionId     = isset($pathParts[2]) ? intval($pathParts[2]) : null;

                $name     = $m['member_name'] ?? '';
                $name     = preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<br/>', '<br>', '<BR/>', '<BR>'], ' ', $name)));
                $gender   = $m['sex'] ?? null;

                // 2.1 更新成員資料 (成員資料是共用的，所以必須更新)
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
                    
                    // ★★★ 過濾點 2：核心修改 ★★★
                    // 如果指定了 limitToItem，且當前迴圈的 meetingId 不符，直接跳過！
                    // 這樣就不會去更新其他項目的 synced_at 了
                    if ($limitToItem && intval($meetingId) !== intval($limitToItem)) {
                        continue;
                    }

                    $status = $m["attend{$idx}"] ?? null;
                    $forceModeInt = (int)$forceMode; 

                    if ($forceMode) {
                        $newStatusExpr = "VALUES(status)";
                    } else {
                        $newStatusExpr = "IF(attendance_records.synced = 0, attendance_records.status, VALUES(status))";
                    }

                    // 強制更新 synced_at 邏輯
                    $sql = "INSERT INTO attendance_records 
                                (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, created_at, synced, synced_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE 
                                status = $newStatusExpr,
                                synced = IF($forceModeInt = 0 AND attendance_records.synced = 0, 0, 1),
                                synced_at = IF(
                                    ($forceModeInt = 1) OR (attendance_records.synced = 1) OR (VALUES(synced) = 1),
                                    NOW(),
                                    attendance_records.synced_at
                                ),
                                updated_at = IF(
                                    (attendance_records.status != VALUES(status)),
                                    NOW(),
                                    attendance_records.updated_at
                                )";

                    $this->conn->prepare($sql)->execute([
                        $memberId, intval($meetingId), $date, $year, $week, $status, $memberDistId, $groupId, $regionId, $m['category'] ?? null
                    ]);
                }
            }
        
        } catch (\PDOException $e) {
            throw new \Exception("資料庫寫入失敗: " . $e->getMessage());
        }
    }
}
?>