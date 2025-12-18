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
    //  User Profile & Line Login Logic (新增區塊)
    // ==========================================

    /**
     * 處理 Line 登入與個人檔案更新的路由分發
     */
    private function handleUserProfile() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        // 嘗試從各種來源取得參數
        $input = json_decode(file_get_contents("php://input"), true);
        $lineUserId = $_GET['line_user_id'] ?? ($_POST['line_user_id'] ?? ($input['line_user_id'] ?? null));
        $lineDisplayName = $_GET['line_display_name'] ?? ($_POST['line_display_name'] ?? ($input['line_display_name'] ?? null));
        
        if (!$lineUserId) {
            throw new Exception("Line User ID 缺失，無法處理用戶資料");
        }

        switch ($method) {
            case 'GET':
                // 讀取個人檔案
                return $this->fetchUserProfile($lineUserId);
            case 'POST':
                // 如果請求中包含 line_display_name，視為「登入自動記錄」
                if ($lineDisplayName) {
                    return $this->loginProfileUpdate($lineUserId, $lineDisplayName);
                }
                // 否則視為「表單送出更新」(只更新 district/email)
                return $this->formProfileUpdate($lineUserId, $input);
            default:
                throw new Exception("不支援的 HTTP 方法");
        }
    }
    
    /**
     * 【登入自動記錄】
     * 處理 Line 登入成功時，自動將 Line ID 和暱稱寫入資料庫
     */
    private function loginProfileUpdate($lineUserId, $lineDisplayName) {
        $sql = "INSERT INTO user_profiles 
                (line_user_id, line_display_name, created_at, updated_at) 
                VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                line_display_name = VALUES(line_display_name), -- 每次登入都更新 Line 暱稱 (如果使用者改名)
                updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$lineUserId, $lineDisplayName]);
        
        // 回傳完整的用戶資料，供前端判斷是否跳轉去填寫大區
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

        // 1. 先抓取舊設定
        $sqlOld = "SELECT sub_district FROM user_profiles WHERE line_user_id = ?";
        $stmtOld = $this->conn->prepare($sqlOld);
        $stmtOld->execute([$lineUserId]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // 2. 更新資料庫
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

        // 3. 【智慧判斷】是否需要同步
        $oldSub = isset($old['sub_district']) ? trim($old['sub_district']) : '';
        $isSubDistrictChanged = ($oldSub !== $subDistrict);
        $runSync = false;

        if ($isSubDistrictChanged) {
            // 只要改了小區，我們就預設執行一次深度同步 (補 4 週)
            // 因為現在參數改為精準的 "7586,3"，我們不能確定舊資料是否包含這些人
            // 所以這裡拿掉 checkRegionDataExists 的阻擋，確保第一次切換時能抓到正確名單
            $this->syncSmallDistrictData($subDistrict);
            $runSync = true;
        }

        return [
            "status" => "success", 
            "message" => $runSync ? "小區名單已更新" : "設定已儲存",
            "synced" => $runSync
        ];
    }

    /**
     * 獲取單一使用者檔案 (增加 monitored_districts 欄位)
     */
    private function fetchUserProfile($lineUserId) {
        // 請確保您的資料表 user_profiles 已新增 monitored_districts 欄位 (TEXT 類型)
        $sql = "SELECT line_user_id, line_display_name, main_district, sub_district, email, monitored_districts 
                FROM user_profiles 
                WHERE line_user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // 如果連 line_user_id 都找不到 (理論上 loginProfileUpdate 已經處理了，但以防萬一)
            $user = [
                'line_user_id' => $lineUserId,
                'line_display_name' => '',
                'main_district' => '',
                'sub_district' => '',
                'email' => '',
            ];
            $profileComplete = false;
        } else {
            // 檢查是否完成必填欄位 (大區和子區)
            $profileComplete = !empty($user['main_district']) && !empty($user['sub_district']);
        }
        return [
            "status" => "success",
            "user" => $user ?: [ 'line_user_id' => $lineUserId, 'main_district' => '', 'sub_district' => '', 'email' => '', 'monitored_districts' => '' ],
            "profileComplete" => !empty($user['main_district']) && !empty($user['sub_district'])
        ];
    }

    // ==========================================
    //  Central System Logic (中央系統互動)
    // ==========================================

    private function syncSmallDistrictData($subDistrictName) {
        $districtMap = (defined('DISTRICT_ID') ? DISTRICT_ID : []);
        $churchIdStr = $districtMap[$subDistrictName] ?? '';
        
        if (empty($churchIdStr)) {
            error_log("[AutoSync] 找不到小區 ID: $subDistrictName");
            return false;
        }

        $churchIdParam = trim($churchIdStr); // 使用完整參數 "7586,3"

        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) return false;

        $syncService = new CentralSyncService();

        // 準備：本週 + 過去 3 週
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

            // URL 保持不變 (roll_call_list 為空以抓取所有項目)
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
            
            // ★★★ 關鍵修改在這裡 ★★★
            if ($data && isset($data['members'])) {
                // 務必傳入 $year 和 $week，否則 CentralSyncService 會全部當成「本週」存入
                $syncService->syncMembersAndAttendance($subDistrictName, $data, $year, $week);
            }
            
            usleep(200000); 
        }
        return true;
    }

    // 對應: central_verify.php (使用 uniqid 增強版)
    private function centralVerify() {
        // 1. 確保 Cookie 資料夾存在且可寫入
        if (!is_writable($this->cookiePath)) {
             @chmod($this->cookiePath, 0777);
        }

        $cleaner = new CookieCleaner(3600);
        $cleaner->cleanPicCookies();

        $picID = uniqid(); // 使用 uniqid 避免前端選擇器問題
        $cookieFile = $this->cookiePath . "/picCookie_" . $picID . ".tmp";
        
        $loginUrl  = CENTRAL_BASE_URL . "/login.php";
        $verifyUrl = CENTRAL_BASE_URL . "/lib/securimage/securimage_show.php";

        // Step 1: 建立 Session
        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $loginContent = curl_exec($ch);
        curl_close($ch);

        // Step 2: 抓圖片
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

        // Step 3: 存圖
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

    // 對應: central_login.php
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

    // 對應: central_session.php
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

    // 對應: central_members.php
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
    
        // 安全解析 JSON
        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = substr($result, 0, 500); 
            error_log("[AttendanceService] Central API JSON Decode Error: " . $preview);
            throw new Exception("中央系統回傳格式錯誤 (非 JSON)，可能是系統維護或權限問題。");
        }
    
        if (!$data || !isset($data['members'])) {
            throw new Exception("中央回傳格式錯誤");
        }
    
        // 啟用同步功能 (將資料存入 members 表)
        $sync = new CentralSyncService();
        $sync->syncMembersAndAttendance($district, $data);
    
        return $data;
    }
    
    // ==========================================
    //  Local Members Logic (核心除錯區)
    // ==========================================
    // 對應: local-members
    // 對應: local-members
    private function localMembers() {
        $itemId = $_GET['item_id'] ?? null;
        $dateInput = $_GET['date'] ?? date("Y-m-d");
        // 新增參數：決定活躍度計算基準 (self=看自己, sunday=看主日)
        $benchmarkMode = $_GET['benchmark_mode'] ?? 'self'; 

        if (!$itemId) throw new Exception("缺少 item_id");

        $dateObj = new DateTime($dateInput);
        
        // 1. 計算本週主日
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $sundayDate = $dateObj->format('Y-m-d');

        // 2. 計算上週主日
        $lastWeekObj = clone $dateObj;
        $lastWeekObj->modify('-7 days');
        $lastSundayDate = $lastWeekObj->format('Y-m-d');

        // 3. 計算一個月前
        $monthAgoDate = (clone $dateObj)->modify('-28 days')->format('Y-m-d');

        $idToNameMap = [];
        if (defined('DISTRICT_ID') && is_array(DISTRICT_ID)) {
            foreach (DISTRICT_ID as $name => $val) {
                $parts = explode(',', $val);
                if (isset($parts[0])) $idToNameMap[trim($parts[0])] = $name;
            }
        }

        // 決定統計活躍度要用的 item_id
        // 如果模式是 'sunday'，強制用 37；否則用當前查詢的 $itemId
        $statsItemId = ($benchmarkMode === 'sunday') ? 37 : $itemId;

        // SQL: 恢復使用 ? 來綁定 statsItemId
        $sql = "SELECT m.member_id, m.name, m.gender, m.group_id, m.region_id, m.category,
                       r.status AS current_status, 
                       r.item_id AS record_item,
                       r_last.status AS last_week_status,
                       (
                           SELECT COUNT(*) 
                           FROM attendance_records ar 
                           WHERE ar.member_id = m.member_id 
                           AND ar.item_id = ?   -- ★ 這裡恢復為變數
                           AND ar.date BETWEEN ? AND ? 
                           AND ar.status = 1
                       ) as monthly_count
                FROM members m
                LEFT JOIN attendance_records r
                  ON m.member_id = r.member_id AND r.date = ? AND r.item_id = ?
                LEFT JOIN attendance_records r_last
                  ON m.member_id = r_last.member_id AND r_last.date = ? AND r_last.item_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        
        // 參數順序:
        // 1. $statsItemId (活躍度基準)
        // 2. $monthAgoDate
        // 3. $sundayDate
        // 4. $sundayDate (current join)
        // 5. $itemId (current join)
        // 6. $lastSundayDate (last join)
        // 7. $itemId (last join) - 注意：上週狀態永遠看「當下這個聚會」的
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

    // 對應: attendance_submit.php
    private function attendanceSubmit() {
        $meetingType = $_POST['meeting_type'] ?? null;
        $memberIds   = $_POST['member_ids'] ?? [];
        $attend      = $_POST['attend'] ?? 1;          // ★ 補回這行
        $inputDate   = $_POST['date'] ?? date("Y-m-d"); // ★ 補回這行

        // 2. 處理 member_ids 格式 (修復 array_merge 錯誤)
        if (is_string($memberIds)) {
            // 如果是字串 "123,456"，就轉成陣列 [123, 456]
            $memberIds = array_filter(explode(',', $memberIds));
        }
        // 確保是純數字陣列，避免 SQL 注入
        $memberIds = array_map('intval', (array)$memberIds);

        if (!$meetingType || empty($memberIds)) throw new Exception("缺少參數");

        $dateObj = new DateTime($inputDate);
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $date = $dateObj->format('Y-m-d');
        $year = (int)$dateObj->format("o");
        $week = (int)$dateObj->format("W");

        // Step 1: 寫入本地 DB
        foreach ($memberIds as $id) {
            $sql = "INSERT INTO attendance_records 
                    (member_id, item_id, date, year, week, status, district_id, created_at, synced) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)
                    ON DUPLICATE KEY UPDATE 
                    status=VALUES(status), synced=0, updated_at=NOW()";
            $this->conn->prepare($sql)->execute([
                $id, $meetingType, $date, $year, $week, $attend, CHURCHID
            ]);
        }

        // Step 2: 呼叫中央 API (使用 Cookie)
        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) {
             return ["status" => "pending", "message" => "已存本地，但中央未登入，無法同步"];
        }

        $postData = [
            'meeting' => $meetingType,
            'year'    => $year,
            'week'    => $week,
            'attend'  => $attend,
            'member_ids' => $memberIds
        ];

        $postString = http_build_query($postData);
        $url = CENTRAL_BASE_URL . "/edit_member_activity.php";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            // 更新本地 synced=1
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $updateSql = "UPDATE attendance_records SET synced=1, synced_at=NOW() 
                          WHERE member_id IN ($placeholders) AND item_id = ? AND date = ?";
            $params = array_merge($memberIds, [$meetingType, $date]);
            $this->conn->prepare($updateSql)->execute($params);

            return ["status" => "success", "message" => "點名成功，中央已同步"];
        } else {
            return ["status" => "pending", "message" => "中央同步失敗，HTTP $httpCode"];
        }
    }
}
?>