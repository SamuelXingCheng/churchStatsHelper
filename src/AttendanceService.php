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
        $mainDistrict = $input['main_district'] ?? null;
        $subDistrict  = $input['sub_district']  ?? null;
        $email        = $input['email']         ?? null;

        if (empty($mainDistrict) || empty($subDistrict)) {
            throw new Exception("大區和小區為必填欄位");
        }

        // 1. 更新 user_profiles (原有的程式碼)
        $sql = "UPDATE user_profiles SET 
                main_district = ?, 
                sub_district = ?, 
                email = ?, 
                updated_at = CURRENT_TIMESTAMP 
                WHERE line_user_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $success = $stmt->execute([$mainDistrict, $subDistrict, $email, $lineUserId]);

        if (!$success) {
            error_log("Database form update failed for Line ID: " . $lineUserId);
            throw new Exception("個人檔案更新失敗，請檢查資料庫連線或權限。");
        }

        // 2. 【新增】觸發自動同步
        // 當使用者選定了小區，我們就順便把那個小區的名單抓下來
        // 這樣本地資料庫就會有資料了，之後選名字才選得到
        
        // 為了不讓使用者等待同步過程 (可能會花幾秒)，
        // 您可以選擇忽略同步結果，或者在前端顯示「同步中」
        // 這裡我們同步執行：
        $this->syncSmallDistrictData($subDistrict);

        return ["status" => "success", "message" => "個人檔案更新成功，並已嘗試同步小區名單"];
    }

    /**
     * 獲取單一使用者檔案 (用於前端展示)
     */
    private function fetchUserProfile($lineUserId) {
        $sql = "SELECT line_user_id, line_display_name, main_district, sub_district, email 
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
            "user" => $user,
            "profileComplete" => $profileComplete, // 前端依此判斷是否跳轉到編輯頁面
            "message" => $profileComplete ? "已完成個人檔案設定" : "請設定大區和小區"
        ];
    }

    // ==========================================
    //  Central System Logic (中央系統互動)
    // ==========================================

    /**
     * 【核心功能】同步指定「小區」的資料
     * 輸入: $subDistrictName (例如 "建成", "永和")
     * 此功能由 formProfileUpdate 自動觸發
     */
    private function syncSmallDistrictData($subDistrictName) {
        // 1. 取得小區 ID 設定
        // 注意：這裡假設 DISTRICT_ID 是所有小區的總表 (key=小區名, value=ID)
        $districtMap = (defined('DISTRICT_ID') ? DISTRICT_ID : []);
        $configValue = $districtMap[$subDistrictName] ?? '';

        if (empty($configValue)) {
            // 如果找不到這個小區的 ID，就無法同步
            error_log("[AutoSync] 找不到小區 ID 設定: $subDistrictName (請確認 config.php 的 DISTRICT_ID)");
            return false;
        }

        // 2. 檢查 Cookie (確認是否已登入中央)
        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) {
            error_log("[AutoSync] 中央系統未登入 (Cookie 不存在)，無法自動同步小區: $subDistrictName");
            // 未來可在這裡加入自動登入 (Auto Login) 的邏輯
            return false;
        }

        // 3. 準備參數抓取資料
        $year = date("Y");
        $week = date("W");
        
        // 組裝 URL：注意這裡 churches[] 放的是小區的 ID
        $url = CENTRAL_BASE_URL . "/list_members.php"
             . "?start=0&limit=2000&year=$year&week=$week" 
             . "&sex=&member_status=&status=&role="        
             . "&search_col=member_name&search=" // 空白代表抓全部
             . "&churches%5B%5D=" . urlencode($configValue) 
             . "&filter_mode=churchStructureTab&roll_call_list="; 

        // 4. 發送 CURL 請求
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $result = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // 5. 驗證結果
        if (strpos($effectiveUrl, 'login.php') !== false) {
             @unlink($cookieFile);
             error_log("[AutoSync] Session 失效，請重新登入中央系統");
             return false;
        }

        $data = json_decode($result, true);
        if (!$data || !isset($data['members'])) {
             error_log("[AutoSync] 中央回傳格式錯誤或該小區無資料");
             return false;
        }

        // 6. 執行資料庫寫入 (呼叫 CentralSyncService)
        try {
            $sync = new CentralSyncService();
            // 這裡傳入 $subDistrictName，讓 SyncService 知道是哪個區
            $sync->syncMembersAndAttendance($subDistrictName, $data);
            error_log("[AutoSync] 成功同步小區: $subDistrictName (ID: $configValue)");
            return true;
        } catch (Exception $e) {
            error_log("[AutoSync] 資料庫寫入失敗: " . $e->getMessage());
            return false;
        }
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
    private function localMembers() {
        $itemId = $_GET['item_id'] ?? null;
        $dateInput = $_GET['date'] ?? date("Y-m-d");

        // error_log("[LocalMembers] Start. item_id={$itemId}");

        if (!$itemId) throw new Exception("缺少 item_id");

        $dateObj = new DateTime($dateInput);
        
        // 1. 計算本週主日 (Target Sunday)
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $sundayDate = $dateObj->format('Y-m-d');

        // 2. 計算上週主日 (用於前端顯示「上週有來」)
        $lastWeekObj = clone $dateObj;
        $lastWeekObj->modify('-7 days');
        $lastSundayDate = $lastWeekObj->format('Y-m-d');

        // 3. 計算一個月前的日期 (用於計算活躍度)
        $monthAgoDate = (clone $dateObj)->modify('-28 days')->format('Y-m-d');

        // 建立 ID 轉 中文名 的對照表 (保留您原本的邏輯)
        $idToNameMap = [];
        if (defined('DISTRICT_ID') && is_array(DISTRICT_ID)) {
            foreach (DISTRICT_ID as $name => $val) {
                $parts = explode(',', $val);
                if (isset($parts[0])) {
                    $trimID = trim($parts[0]);
                    $idToNameMap[$trimID] = $name;
                }
            }
        }

        // 【升級版 SQL】
        // 新增: r_last (上週狀態), monthly_count (近4週次數)
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
                -- Join 本週紀錄
                LEFT JOIN attendance_records r
                  ON m.member_id = r.member_id AND r.date = ? AND r.item_id = ?
                -- Join 上週紀錄
                LEFT JOIN attendance_records r_last
                  ON m.member_id = r_last.member_id AND r_last.date = ? AND r_last.item_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        
        // 參數順序需嚴格對應 SQL ?
        $stmt->execute([
            $itemId,        // monthly_count item
            $monthAgoDate,  // monthly_count start
            $sundayDate,    // monthly_count end
            $sundayDate,    // current date
            $itemId,        // current item
            $lastSundayDate,// last week date
            $itemId         // last week item
        ]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // error_log("[LocalMembers] DB fetched rows: " . count($rows));

        // 轉換資料
        $members = array_map(function ($row) use ($itemId, $idToNameMap) {
            
            // ID 轉 中文名
            $rId = $row["region_id"] ?? "";
            $rName = $idToNameMap[$rId] ?? $rId; // 找不到就回傳 ID
            
            return [
                "member_id"        => intval($row["member_id"]),
                "member_name"      => $row["name"],
                "sex"              => $row["gender"],
                "small_group_name" => $rName, // 前端顯示用
                "item_id"          => intval($row["record_item"] ?? $itemId),
                "status"           => is_null($row["current_status"]) ? null : intval($row["current_status"]),
                
                // 新增欄位給前端智慧功能使用
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
        $attend      = $_POST['attend'] ?? 1;
        $inputDate   = $_POST['date'] ?? date("Y-m-d");

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