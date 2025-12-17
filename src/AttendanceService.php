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

    /**
     * 【表單編輯更新】
     * 處理使用者手動提交的大區、小區、Email 更新
     */
    private function formProfileUpdate($lineUserId, $input) {
        $mainDistrict = $input['main_district'] ?? null;
        $subDistrict  = $input['sub_district']  ?? null;
        $email        = $input['email']         ?? null;

        if (empty($mainDistrict) || empty($subDistrict)) {
            throw new Exception("大區和小區為必填欄位");
        }

        // 僅更新非 Line 基礎資料
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

        return ["status" => "success", "message" => "個人檔案更新成功"];
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
    private function localMembers() {
        $itemId = $_GET['item_id'] ?? null;
        $dateInput = $_GET['date'] ?? date("Y-m-d");

        error_log("[LocalMembers] Start. item_id={$itemId}");

        if (!$itemId) throw new Exception("缺少 item_id");

        $dateObj = new DateTime($dateInput);
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $sundayDate = $dateObj->format('Y-m-d');
        
        // ★★★ DEBUG 重點 1: 檢查設定檔讀取狀況 ★★★
        if (defined('DISTRICT_ID')) {
            $mapCount = is_array(DISTRICT_ID) ? count(DISTRICT_ID) : 0;
            error_log("[LocalMembers] DISTRICT_ID is defined. Count: " . $mapCount);
            // 如果數量是 0，代表 .env 讀取失敗或格式錯誤
            if ($mapCount === 0) {
                error_log("[LocalMembers] CRITICAL WARNING: DISTRICT_ID array is empty! Check your .env DISTRICT_MAPS.");
            }
        } else {
            error_log("[LocalMembers] CRITICAL ERROR: DISTRICT_ID constant is NOT defined!");
        }

        // 建立 ID 轉 中文名 的對照表
        $idToNameMap = [];
        if (defined('DISTRICT_ID') && is_array(DISTRICT_ID)) {
            foreach (DISTRICT_ID as $name => $val) {
                // $val 格式為 "7586,3" => 取出 7586
                $parts = explode(',', $val);
                if (isset($parts[0])) {
                    $trimID = trim($parts[0]);
                    $idToNameMap[$trimID] = $name;
                }
            }
        }

        // SQL 查詢
        $sql = "SELECT m.member_id, m.name, m.gender, m.group_id, m.region_id, m.category,
                       r.status, r.item_id AS record_item
                FROM members m
                LEFT JOIN attendance_records r
                  ON m.member_id = r.member_id AND r.date = ? AND r.item_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$sundayDate, $itemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("[LocalMembers] DB fetched rows: " . count($rows));

        // 轉換資料
        $firstLog = false;
        $members = array_map(function ($row) use ($itemId, $idToNameMap, &$firstLog) {
            
            // ★ 將資料庫裡的 ID (7586) 轉回 中文 (永和)
            $rId = $row["region_id"] ?? "";
            $rName = $idToNameMap[$rId] ?? $rId; // 找不到就回傳 ID
            
            // ★★★ DEBUG 重點 2: 印出第一筆轉換結果 ★★★
            if (!$firstLog) {
                error_log("[LocalMembers] Mapping Check: ID [{$rId}] => Name [{$rName}]");
                if ($rId == $rName) {
                    error_log("[LocalMembers] MAPPING FAILED: ID was not converted to Name. Check DISTRICT_ID config.");
                } else {
                    error_log("[LocalMembers] MAPPING SUCCESS: ID converted to Name.");
                    error_log("[LocalMembers] Name First Char: " . mb_substr($row["name"], 0, 3, "UTF-8"));
                }
                $firstLog = true;
            }
            
            return [
                "member_id"   => intval($row["member_id"]),
                "member_name" => $row["name"],
                "sex"         => $row["gender"],
                "small_group_name" => $rName, // 前端要的中文
                "item_id"     => intval($row["record_item"] ?? $itemId),
                "status"      => is_null($row["status"]) ? null : intval($row["status"]),
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