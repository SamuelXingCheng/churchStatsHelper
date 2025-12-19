<?php
// src/AttendanceService.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CookieCleaner.php';
require_once __DIR__ . '/CentralSyncService.php';

class AttendanceService {
    private $conn;
    private $cookiePath;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct() {
        if (!defined('CHURCHGROUP') || !defined('CENTRAL_USERNAME') || !defined('CENTRAL_BASE_URL')) {
            error_log("[AttendanceService] Warning: Missing env configuration.");
        }
        $this->conn = Database::getInstance()->getConnection();
        $this->cookiePath = __DIR__ . "/../cookie";
        if (!file_exists($this->cookiePath)) {
            mkdir($this->cookiePath, 0777, true);
        }
    }

    public function handleRequest($path) {
        try {
            // error_log("[AttendanceService] Request Path: " . $path);
            switch ($path) {
                case "central-verify":    return $this->centralVerify();
                case "central-login":     return $this->centralLogin();
                case "central-session":   return $this->centralSession();
                case "central-members":   return $this->centralMembers();
                case "central-attendance":
                case "attendance-submit": return $this->attendanceSubmit(); 
                case "local-members":     return $this->localMembers();
                case "user-profile":      return $this->handleUserProfile();
                default: throw new Exception("Unknown path: $path");
            }
        } catch (Exception $e) {
            error_log("[AttendanceService] Error: " . $e->getMessage());
            return ["status" => "error", "message" => $e->getMessage()];
        }
    }

    // ==========================================
    //  User Profile & Line Login Logic
    // ==========================================

    private function handleUserProfile() {
        $method = $_SERVER['REQUEST_METHOD'];
        $input = json_decode(file_get_contents("php://input"), true);
        $lineUserId = $_GET['line_user_id'] ?? ($_POST['line_user_id'] ?? ($input['line_user_id'] ?? null));
        $lineDisplayName = $_GET['line_display_name'] ?? ($_POST['line_display_name'] ?? ($input['line_display_name'] ?? null));
        
        if (!$lineUserId) {
            throw new Exception("Line User ID 缺失，無法處理用戶資料");
        }

        switch ($method) {
            case 'GET':
                return $this->fetchUserProfile($lineUserId);
            case 'POST':
                if ($lineDisplayName) {
                    return $this->loginProfileUpdate($lineUserId, $lineDisplayName);
                }
                return $this->formProfileUpdate($lineUserId, $input);
            default:
                throw new Exception("不支援的 HTTP 方法");
        }
    }
    
    private function loginProfileUpdate($lineUserId, $lineDisplayName) {
        $sql = "INSERT INTO user_profiles 
                (line_user_id, line_display_name, created_at, updated_at) 
                VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                line_display_name = VALUES(line_display_name),
                updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$lineUserId, $lineDisplayName]);
        
        return $this->fetchUserProfile($lineUserId);
    }

    private function formProfileUpdate($lineUserId, $input) {
        $mainDistrict = trim($input['main_district'] ?? '');
        $subDistrict  = trim($input['sub_district'] ?? '');
        $email        = trim($input['email'] ?? '');
        $monitored    = trim($input['monitored_districts'] ?? '');

        if (empty($mainDistrict) || empty($subDistrict)) {
            throw new Exception("大區和小區為必填欄位");
        }

        $sqlOld = "SELECT sub_district FROM user_profiles WHERE line_user_id = ?";
        $stmtOld = $this->conn->prepare($sqlOld);
        $stmtOld->execute([$lineUserId]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $sql = "UPDATE user_profiles SET 
                main_district = ?, 
                sub_district = ?, 
                email = ?, 
                monitored_districts = ?,
                updated_at = CURRENT_TIMESTAMP 
                WHERE line_user_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $success = $stmt->execute([$mainDistrict, $subDistrict, $email, $monitored, $lineUserId]);

        if (!$success) throw new Exception("個人檔案更新失敗");

        $oldSub = isset($old['sub_district']) ? trim($old['sub_district']) : '';
        $isSubDistrictChanged = ($oldSub !== $subDistrict);
        $runSync = false;

        if ($isSubDistrictChanged) {
            $this->syncSmallDistrictData($subDistrict);
            $runSync = true;
        }

        return [
            "status" => "success", 
            "message" => $runSync ? "小區名單已更新" : "設定已儲存",
            "synced" => $runSync
        ];
    }

    private function fetchUserProfile($lineUserId) {
        $sql = "SELECT line_user_id, line_display_name, main_district, sub_district, email, monitored_districts 
                FROM user_profiles 
                WHERE line_user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $user = [
                'line_user_id' => $lineUserId,
                'line_display_name' => '',
                'main_district' => '',
                'sub_district' => '',
                'email' => '',
            ];
            $profileComplete = false;
        } else {
            $profileComplete = !empty($user['main_district']) && !empty($user['sub_district']);
        }
        return [
            "status" => "success",
            "user" => $user ?: [ 'line_user_id' => $lineUserId, 'main_district' => '', 'sub_district' => '', 'email' => '', 'monitored_districts' => '' ],
            "profileComplete" => !empty($user['main_district']) && !empty($user['sub_district'])
        ];
    }

    // ==========================================
    //  Central System Logic
    // ==========================================

    private function syncSmallDistrictData($subDistrictName) {
        $districtMap = (defined('DISTRICT_ID') ? DISTRICT_ID : []);
        $churchIdStr = $districtMap[$subDistrictName] ?? '';
        
        if (empty($churchIdStr)) {
            error_log("[AutoSync] 找不到小區 ID: $subDistrictName");
            return false;
        }

        $churchIdParam = trim($churchIdStr);
        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) return false;

        $syncService = new CentralSyncService();

        $weeks = [];
        for ($i = 0; $i < 4; $i++) {
            $d = new DateTime();
            $d->modify("-$i week");
            $weeks[] = [
                'year' => (int)$d->format("o"),
                'week' => (int)$d->format("W")
            ];
        }

        foreach ($weeks as $w) {
            $year = $w['year'];
            $week = sprintf("%02d", $w['week']);

            $url = CENTRAL_BASE_URL . "/list_members.php"
                 . "?start=0&limit=1000&year=$year&week=$week" 
                 . "&churches%5B%5D=" . urlencode($churchIdParam) 
                 . "&filter_mode=churchStructureTab"
                 . "&roll_call_list="; 

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $result = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($result, true);
            
            if ($data && isset($data['members'])) {
                $syncService->syncMembersAndAttendance($subDistrictName, $data, $year, $week);
            }
            
            usleep(200000); 
        }
        return true;
    }

    private function centralVerify() {
        if (!is_writable($this->cookiePath)) {
             @chmod($this->cookiePath, 0777);
        }

        $cleaner = new CookieCleaner(3600);
        $cleaner->cleanPicCookies();

        $picID = uniqid();
        $cookieFile = $this->cookiePath . "/picCookie_" . $picID . ".tmp";
        
        $loginUrl  = CENTRAL_BASE_URL . "/login.php";
        $verifyUrl = CENTRAL_BASE_URL . "/lib/securimage/securimage_show.php";

        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);

        $ch = curl_init($verifyUrl);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $imageData = curl_exec($ch);
        $imgHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($imgHttpCode != 200 || empty($imageData)) {
            throw new Exception("無法下載驗證碼圖片 (HTTP $imgHttpCode)");
        }

        $picPath = __DIR__ . "/../pic";
        if (!file_exists($picPath)) mkdir($picPath, 0777, true);
        
        $fileName = "pic_" . $picID . ".jpg";
        file_put_contents($picPath . "/" . $fileName, $imageData);

        return [
            "status"  => "success",
            "message" => "驗證碼圖片已存檔",
            "url"     => "./pic/" . $fileName,
            "picID"   => $picID
        ];
    }

    private function centralLogin() {
        $input = json_decode(file_get_contents("php://input"), true);
        $verifyCode = $input['verifyCode'] ?? $_POST['verifyCode'] ?? null;
        $picID      = $input['picID']      ?? $_POST['picID']      ?? null;

        if (!$verifyCode || !$picID) throw new Exception("缺少驗證碼或 picID");

        $cookieFile = $this->cookiePath . "/picCookie_" . $picID . ".tmp";
        if (!file_exists($cookieFile)) throw new Exception("找不到 cookie，請重新整理驗證碼");

        $postFields = [
            "district"      => CHURCHGROUP,
            "church_id"     => CHURCHID,
            "account"       => CENTRAL_USERNAME,
            "pwd"           => CENTRAL_PASSWORD,
            "language"      => "zh-tw",
            "captcha_code"  => $verifyCode
        ];

        $ch = curl_init(CENTRAL_BASE_URL . "/authenticate.php");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_REFERER, CENTRAL_BASE_URL . '/login.php');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && strpos($response, "登入失敗") === false) {
            $centralCookieFile = $this->cookiePath . "/central_cookie.tmp";
            if (file_exists($centralCookieFile)) @unlink($centralCookieFile);
            rename($cookieFile, $centralCookieFile);
            return ["success" => true, "message" => "登入成功"];
        } else {
            return ["success" => false, "message" => "登入失敗，請檢查驗證碼"];
        }
    }

    private function centralSession() {
        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) return ["loggedIn" => false, "message" => "未登入"];

        $ch = curl_init(CENTRAL_BASE_URL . "/index.php");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (strpos($effectiveUrl, 'login.php') !== false || strpos($response, "帳號/Account") !== false) {
            @unlink($cookieFile);
            return ["loggedIn" => false, "message" => "Session 已過期，請重新登入"];
        }

        return ["loggedIn" => true, "message" => "已登入"];
    }

    private function centralMembers() {
        $district = $_GET['district'] ?? ''; 
        $search   = $_GET['search']   ?? '';

        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) {
            throw new Exception("Cookie 不存在，請先執行登入");
        }
    
        $year = date("Y");
        $week = date("W");
    
        $districtMap = (defined('DISTRICT_ID') ? DISTRICT_ID : []);
        $configValue = $districtMap[$district] ?? ''; 
    
        if (empty($configValue)) {
            throw new Exception("找不到對應的大區 ID 設定");
        }
    
        $url = CENTRAL_BASE_URL . "/list_members.php"
             . "?start=0&limit=2000&year=$year&week=$week" 
             . "&sex=&member_status=&status=&role="        
             . "&search_col=member_name&search=" . urlencode($search)
             . "&churches%5B%5D=" . urlencode($configValue) 
             . "&filter_mode=churchStructureTab&roll_call_list="; 
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_REFERER, CENTRAL_BASE_URL . '/');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
        $result = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
    
        if (strpos($effectiveUrl, 'login.php') !== false) {
             @unlink($cookieFile);
             throw new Exception("Session 失效，請重新登入。");
        }
    
        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = substr($result, 0, 500); 
            error_log("[AttendanceService] Central API JSON Decode Error: " . $preview);
            throw new Exception("中央系統回傳格式錯誤 (非 JSON)，可能是系統維護或權限問題。");
        }
    
        if (!$data || !isset($data['members'])) {
            throw new Exception("中央回傳格式錯誤");
        }
    
        $sync = new CentralSyncService();
        $sync->syncMembersAndAttendance($district, $data);
    
        return $data;
    }
    
    // ==========================================
    //  Local Members Logic
    // ==========================================
    private function localMembers() {
        $itemId = $_GET['item_id'] ?? null;
        $dateInput = $_GET['date'] ?? date("Y-m-d");
        $benchmarkMode = $_GET['benchmark_mode'] ?? 'self'; 

        if (!$itemId) throw new Exception("缺少 item_id");

        $dateObj = new DateTime($dateInput);
        
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $sundayDate = $dateObj->format('Y-m-d');

        $lastWeekObj = clone $dateObj;
        $lastWeekObj->modify('-7 days');
        $lastSundayDate = $lastWeekObj->format('Y-m-d');

        $monthAgoDate = (clone $dateObj)->modify('-28 days')->format('Y-m-d');

        $idToNameMap = [];
        if (defined('DISTRICT_ID') && is_array(DISTRICT_ID)) {
            foreach (DISTRICT_ID as $name => $val) {
                $parts = explode(',', $val);
                if (isset($parts[0])) $idToNameMap[trim($parts[0])] = $name;
            }
        }

        $statsItemId = ($benchmarkMode === 'sunday') ? 37 : $itemId;

        $sql = "SELECT m.member_id, m.name, m.gender, m.group_id, m.region_id, m.category,
                       r.status AS current_status, 
                       r.item_id AS record_item,
                       r_last.status AS last_week_status,
                       (
                           SELECT COUNT(*) 
                           FROM attendance_records ar 
                           WHERE ar.member_id = m.member_id 
                           AND ar.item_id = ?   
                           AND ar.date BETWEEN ? AND ? 
                           AND ar.status = 1
                       ) as monthly_count
                FROM members m
                LEFT JOIN attendance_records r
                  ON m.member_id = r.member_id AND r.date = ? AND r.item_id = ?
                LEFT JOIN attendance_records r_last
                  ON m.member_id = r_last.member_id AND r_last.date = ? AND r_last.item_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        
        $stmt->execute([
            $statsItemId,   
            $monthAgoDate, 
            $sundayDate, 
            $sundayDate, 
            $itemId, 
            $lastSundayDate, 
            $itemId
        ]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $members = array_map(function ($row) use ($itemId, $idToNameMap) {
            $rId = $row["region_id"] ?? "";
            $rName = $idToNameMap[$rId] ?? $rId; 
            
            return [
                "member_id"        => intval($row["member_id"]),
                "member_name"      => $row["name"],
                "sex"              => $row["gender"],
                "small_group_name" => $rName,
                "item_id"          => intval($row["record_item"] ?? $itemId),
                "status"           => is_null($row["current_status"]) ? null : intval($row["current_status"]),
                "last_week_status" => is_null($row["last_week_status"]) ? 0 : intval($row["last_week_status"]),
                "monthly_count"    => intval($row["monthly_count"])
            ];
        }, $rows);

        return ["status" => "success", "date" => $sundayDate, "members" => $members];
    }

    // ==========================================
    //  Attendance Submit Logic (Soft Delete / Update to NULL)
    // ==========================================
    private function attendanceSubmit() {
        $meetingType = $_POST['meeting_type'] ?? null;
        $memberIds   = $_POST['member_ids'] ?? [];
        $attend      = $_POST['attend'] ?? 1;
        $inputDate   = $_POST['date'] ?? date("Y-m-d");
    
        // 1. Log
        error_log("[Attendance] 開始處理點名 - Type: $meetingType, Date: $inputDate");
    
        // 2. 處理 member_ids 格式
        if (is_string($memberIds)) {
            $memberIds = array_filter(explode(',', $memberIds));
        }
        $newMemberIds = array_map('intval', (array)$memberIds); 
        
        if (!$meetingType) {
            throw new Exception("缺少參數: meeting_type");
        }
    
        // 3. 計算日期定位
        $dateObj = new DateTime($inputDate);
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $date = $dateObj->format('Y-m-d');
        $year = (int)$dateObj->format("o");
        $week = (int)$dateObj->format("W");
    
        // =========================================================
        // Step A: 找出「被取消」的人 (Diff Check)
        // =========================================================
        $cancelledIds = [];
        try {
            // 找出原本狀態為 1 的人
            $sqlCheck = "SELECT member_id FROM attendance_records 
                         WHERE date = ? AND item_id = ? AND status = 1";
            $stmtCheck = $this->conn->prepare($sqlCheck);
            $stmtCheck->execute([$date, $meetingType]);
            $existingIds = $stmtCheck->fetchAll(PDO::FETCH_COLUMN, 0); 
            $existingIds = array_map('intval', $existingIds);

            // 計算差集
            $cancelledIds = array_diff($existingIds, $newMemberIds);
        } catch (Exception $e) {
            error_log("[Attendance] 讀取舊名單失敗: " . $e->getMessage());
        }

        // =========================================================
        // Step B: 本地資料庫更新
        // =========================================================
        try {
            if (method_exists($this->conn, 'beginTransaction')) {
                $this->conn->beginTransaction();
            }

            // 1. 執行「取消」 (UPDATE status = NULL)
            // 這部分邏輯不變，只更新 status，不會動到 group_id/region_id
            if (!empty($cancelledIds)) {
                $placeholders = implode(',', array_fill(0, count($cancelledIds), '?'));
                $sqlUpdate = "UPDATE attendance_records 
                              SET status = NULL, synced = 0, updated_at = NOW() 
                              WHERE date = ? AND item_id = ? 
                              AND member_id IN ($placeholders)";
                $params = array_merge([$date, $meetingType], $cancelledIds);
                $this->conn->prepare($sqlUpdate)->execute($params);
            }

            // 2. 執行「新增/出席」 (INSERT with Full Details)
            if (!empty($newMemberIds)) {
                
                // ★ 新增步驟：先去 members 表查出這些人的詳細資料 (group_id, region_id, category)
                $placeholders = implode(',', array_fill(0, count($newMemberIds), '?'));
                $sqlDetails = "SELECT member_id, group_id, region_id, category 
                               FROM members WHERE member_id IN ($placeholders)";
                $stmtDetails = $this->conn->prepare($sqlDetails);
                $stmtDetails->execute($newMemberIds);
                
                // 將結果轉為以 member_id 為 Key 的陣列，方便查找
                $memberInfos = [];
                while ($row = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                    $memberInfos[$row['member_id']] = $row;
                }

                // ★ 修改 SQL：加入 group_id, region_id, category 欄位
                $sqlInsert = "INSERT INTO attendance_records 
                              (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, created_at, synced) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
                              ON DUPLICATE KEY UPDATE 
                              status = 1, synced = 0, updated_at = NOW()"; 
                              // 注意：Duplicate 時我們通常只更新 status，不一定要更新 group_id，除非你想確保資料永遠最新

                $stmtInsert = $this->conn->prepare($sqlInsert);

                foreach ($newMemberIds as $id) {
                    // 從剛剛查到的資料中取出對應值
                    $info = $memberInfos[$id] ?? [];
                    $groupId  = $info['group_id'] ?? null;
                    $regionId = $info['region_id'] ?? null;
                    $category = $info['category'] ?? null;

                    $stmtInsert->execute([
                        $id, 
                        $meetingType, 
                        $date, 
                        $year, 
                        $week, 
                        1, 
                        CHURCHID, 
                        $groupId,  // 補上 group_id
                        $regionId, // 補上 region_id
                        $category  // 補上 category
                    ]);
                }
            }

            if (method_exists($this->conn, 'commit')) {
                $this->conn->commit();
            }

        } catch (Exception $e) {
            if (method_exists($this->conn, 'rollBack')) {
                $this->conn->rollBack();
            }
            error_log("[Attendance] 本地 DB 寫入失敗: " . $e->getMessage());
            return ["status" => "error", "message" => "本地資料庫寫入失敗"];
        }
    
        // =========================================================
        // Step C: 中央同步 (邏輯不變)
        // =========================================================
        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) {
            return ["status" => "success", "message" => "已存本地 (Full Info)，但中央未連線"];
        }

        $url = CENTRAL_BASE_URL . "/edit_member_activity.php";
        $syncErrors = 0;

        $addedIds = array_diff($newMemberIds, $existingIds);

        // 同步 1: 出席 (Status = 1)
        if (!empty($addedIds)) { // <--- 這裡改成檢查 addedIds
            $postData = [
                'meeting'    => $meetingType,
                'year'       => $year,
                'week'       => $week,
                'attend'     => 1, 
                'member_ids' => array_values($addedIds) // <--- 這裡只送出真正新增的人
            ];
            if (!$this->sendToCentral($url, $postData, $cookieFile)) $syncErrors++;
        }

        // 同步 2: 取消 (Status = 0)
        if (!empty($cancelledIds)) {
            $postData = [
                'meeting'    => $meetingType,
                'year'       => $year,
                'week'       => $week,
                'attend'     => 0, 
                'member_ids' => array_values($cancelledIds) 
            ];
            if (!$this->sendToCentral($url, $postData, $cookieFile)) $syncErrors++;
        }

        if ($syncErrors === 0) {
            $allProcessedIds = array_merge($newMemberIds, $cancelledIds);
            if (!empty($allProcessedIds)) {
                $placeholders = implode(',', array_fill(0, count($allProcessedIds), '?'));
                $updateSql = "UPDATE attendance_records SET synced=1, synced_at=NOW() 
                              WHERE member_id IN ($placeholders) AND item_id = ? AND date = ?";
                $this->conn->prepare($updateSql)->execute(array_merge($allProcessedIds, [$meetingType, $date]));
            }
            return ["status" => "success", "message" => "點名成功，中央已完整同步"];
        } else {
            return ["status" => "pending", "message" => "本地已更新，但中央同步部分失敗"];
        }
    }
    // 輔助函式：發送 curl 請求
    private function sendToCentral($url, $postData, $cookieFile) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode == 200);
    }
}
?>