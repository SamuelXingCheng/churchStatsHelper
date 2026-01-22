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

        // 1. 直接更新資料庫 (不再先查詢舊資料比對，節省一次 SQL)
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

        // 2. ★ 關鍵修改：移除自動同步邏輯
        // 不再呼叫 syncSmallDistrictData，確保 API 能在 0.1 秒內回傳。
        // 如果該小區是第一次使用 (本地沒資料)，使用者會在列表頁看到空白，
        // 此時他們可以點擊前端的「手動同步」按鈕來抓取資料。

        return [
            "status" => "success", 
            "message" => "設定已儲存",
            "synced" => false // 明確告訴前端這次沒有執行同步
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
        
        // 1. 如果 Cookie 檔案根本不存在，直接判斷未登入
        if (!file_exists($cookieFile)) {
            return ["loggedIn" => false, "message" => "未登入 (Cookie 缺失)"];
        }

        // 2. 嘗試連線到中央首頁
        $ch = curl_init(CENTRAL_BASE_URL . "/index.php");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // ★★★ 修正重點：加大逾時寬容度 (配合慢速伺服器) ★★★
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);        // 允許執行 120 秒
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);  // 允許連線耗時 30 秒
        
        $response = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 取得 HTTP 狀態碼
        curl_close($ch);

        // 3. 判斷是否連線失敗 (Timeout 或 DNS 錯誤)
        if ($response === false || $httpCode === 0) {
            // 雖然連線失敗，但為了不讓使用者恐慌，
            // 如果我們手上有 Cookie，可以暫時回傳 "unknown" 或視為 "可能還登入著"
            // 但標準做法是回傳錯誤，讓前端重試
            return ["loggedIn" => false, "message" => "連線逾時，請稍後再試"];
        }

        // 4. 判斷是否被踢回登入頁
        if (strpos($effectiveUrl, 'login.php') !== false || 
            strpos($response, "帳號/Account") !== false || 
            strpos($response, "登入") !== false) {
            
            @unlink($cookieFile); // 確定失效了，刪除 Cookie
            return ["loggedIn" => false, "message" => "Session 已過期，請重新登入"];
        }

        // 5. 通過所有檢查
        return ["loggedIn" => true, "message" => "已登入"];
    }

    private function centralMembers() {
        $district = $_GET['district'] ?? ''; 
        $search   = $_GET['search']   ?? '';
        $dateInput = $_GET['date'] ?? date("Y-m-d");

        // [優化 1] 接收前端傳來的特定聚會類型 (如果有傳的話)
        $targetMeetingType = $_GET['meeting_type'] ?? ''; 

        // =========================================================
        // 🛡️ 防禦機制 1：分區獨立快取 (Per-District Caching)
        // =========================================================
        // [優化 2] 檔名加入 md5(小區 + 日期 + 聚會類型)
        // 這是關鍵：如果不加 meeting_type，切換聚會時會讀到舊的快取，導致資料錯誤
        $cacheKey = md5($district . '_' . $dateInput . '_' . $targetMeetingType);
        $cacheFile = __DIR__ . "/../cache/members_" . $cacheKey . ".json";
        
        // 如果快取存在且在 60 秒內建立的 (避免頻繁請求)
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 60)) {
            $cachedContent = file_get_contents($cacheFile);
            return json_decode($cachedContent, true);
        }

        // =========================================================
        // 🛡️ 防禦機制 2：全域隨機速率限制 (Global Random Jitter)
        // =========================================================
        $lockFile = __DIR__ . "/../cache/global_last_request.txt";
        
        if (file_exists($lockFile)) {
            $lastTime = (int)file_get_contents($lockFile);
            $diff = time() - $lastTime;
            $safeGap = rand(2, 4); 
            
            if ($diff < $safeGap) {
                sleep($safeGap - $diff); 
            }
        }
        file_put_contents($lockFile, time());

        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) {
            throw new Exception("401 Unauthorized: Cookie 不存在，請先執行登入");
        }
    
        $ts = strtotime($dateInput);
        $year = date("o", $ts); 
        $week = date("W", $ts);
    
        $districtMap = (defined('DISTRICT_ID') ? DISTRICT_ID : []);
        $configValue = $districtMap[$district] ?? ''; 
    
        if (empty($configValue)) {
            throw new Exception("找不到對應的大區 ID 設定");
        }
    
        // =========================================================
        // [優化 3] 決定要抓哪些名單 (瘦身模式)
        // =========================================================
        if (!empty($targetMeetingType)) {
            // A. 精準模式：只抓目前正在看的這個聚會 (速度快、不易逾時)
            $rollCallListStr = $targetMeetingType;
        } else {
            // B. 全量模式：沒指定時才抓全部 (相容舊版)
            $allMeetings = [
                Lordsday, Pray, smallGroup, home_MEETING, 
                go_home_MEETING, Gospel, Revival, ChildrenGroup, LifeStudy
            ];
            $rollCallListStr = implode(',', $allMeetings);
        }
    
        $url = CENTRAL_BASE_URL . "/list_members.php"
             . "?start=0&limit=2000&year=$year&week=$week" 
             . "&sex=&member_status=&status=&role="        
             . "&search_col=member_name&search=" . urlencode($search)
             . "&churches%5B%5D=" . urlencode($configValue) 
             . "&filter_mode=churchStructureTab"
             . "&roll_call_list=" . $rollCallListStr; 
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_REFERER, CENTRAL_BASE_URL . '/');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // [優化 4] 增加 Timeout 設定 (避免卡死)
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    
        $result = curl_exec($ch);
        
        // --- 錯誤處理開始 ---
        if ($result === false) {
            $curlError = curl_error($ch);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);
            error_log("[AttendanceService] cURL Failed. ErrNo: $curlErrNo, Error: $curlError");
            throw new Exception("連線中央系統失敗 (cURL Error): $curlError");
        }

        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            if ($httpCode === 401 || $httpCode === 403) {
                 @unlink($cookieFile);
                 throw new Exception("401 Unauthorized: 連線金鑰已失效，請重新登入。");
            }
            throw new Exception("中央系統連線異常 (HTTP Code: $httpCode)");
        }

        if (empty($result)) {
            throw new Exception("中央系統回傳空白內容 (Empty Response)，請稍後再試。");
        }
    
        if (strpos($effectiveUrl, 'login.php') !== false) {
             @unlink($cookieFile);
             throw new Exception("401 Unauthorized: Session Redirect，請重新登入。");
        }
        // --- 錯誤處理結束 ---
    
        $data = json_decode($result, true);
        
        // --- JSON 解析與智慧判斷 ---
        if (json_last_error() !== JSON_ERROR_NONE) {
            
            $preview = mb_substr(strip_tags($result), 0, 100); 
            error_log("[AttendanceService] JSON Decode Error. Preview: " . $preview);

            // 檢查是否包含登入關鍵字
            if (stripos($result, 'login') !== false || 
                stripos($result, 'password') !== false || 
                stripos($result, 'securimage') !== false ||
                stripos($result, '登入') !== false) {
                
                @unlink($cookieFile); 
                throw new Exception("401 Unauthorized: 連線金鑰已過期，請重新登入。");
            }

            throw new Exception("中央系統回傳非 JSON 格式 (可能系統維護中)。內容預覽：" . $preview);
        }
    
        if (!$data || !isset($data['members'])) {
            throw new Exception("中央回傳格式缺損 (缺少 members 欄位)");
        }

        // =========================================================
        // 💾 成功後存檔
        // =========================================================
        
        file_put_contents($cacheFile, json_encode($data));
    
        $sync = new CentralSyncService();
        
        // 傳入參數說明：
        // 1. $district
        // 2. $data
        // 3. $year
        // 4. $week
        // 5. $forceMode = false (預設)
        // 6. $skipSeconds = 0 (手動同步不略過，確保時間更新)
        // 7. $limitToItem = $targetMeetingType (★ 限制只更新這個聚會)
        
        $sync->syncMembersAndAttendance(
            $district, 
            $data, 
            $year, 
            $week, 
            false, 
            0,     // 這裡設為 0，確保手動同步時一定會寫入並更新時間
            $targetMeetingType // 把我們剛剛接到的 meeting_type 傳進去
        );
    
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
                   r.synced,
                   r.synced_at,             /* <--- ★ 修改：從 updated_at 改為 synced_at */
                   r.updated_at,
                   r.last_sync_error,
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
                
                // ★ 新增這行：處理同步狀態 (預設為 0)
                "synced"           => intval($row["synced"] ?? 0),
                "synced_at"        => $row["synced_at"],
                "updated_at"       => $row["updated_at"],
                "last_sync_error"  => $row["last_sync_error"] ?? null,

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
        $inputDate   = $_POST['date'] ?? date("Y-m-d");
        
        // ★ 1. 接收範圍參數
        $subDistrict   = $_POST['sub_district'] ?? null; 
        $customGroupId = $_POST['custom_group_id'] ?? null; // 新增：自訂名單 ID

        error_log("[Attendance] 開始處理點名 - Type: $meetingType, Date: $inputDate, Sub: $subDistrict, Group: $customGroupId");

        if (is_string($memberIds)) {
            $memberIds = array_filter(explode(',', $memberIds));
        }
        $newMemberIds = array_map('intval', (array)$memberIds); 
        
        if (!$meetingType) {
            throw new Exception("缺少參數: meeting_type");
        }

        // 計算日期定位
        $dateObj = new DateTime($inputDate);
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $date = $dateObj->format('Y-m-d');
        $year = (int)$dateObj->format("o");
        $week = (int)$dateObj->format("W");

        // 解析小區 ID (Region ID)
        $targetRegionId = null;
        if ($subDistrict && defined('DISTRICT_ID')) {
            $districtMap = DISTRICT_ID;
            $val = $districtMap[$subDistrict] ?? null;
            if ($val) {
                $parts = explode(',', $val);
                $targetRegionId = isset($parts[0]) ? intval($parts[0]) : null;
            }
        }

        // =========================================================
        // Step A: 找出「範圍內」被取消的人 (Diff Check)
        // =========================================================
        $existingIds = [];
        $cancelledIds = [];
        $addedIds = [];

        try {
            $sqlCheck = "SELECT member_id FROM attendance_records 
                         WHERE date = ? AND item_id = ? AND status = 1";
            $paramsCheck = [$date, $meetingType];
            
            // ★ 2. 關鍵修改：嚴格的範圍鎖定邏輯
            $scopeFound = false; // 用來標記是否找到合法的「刪除範圍」

            if ($customGroupId) {
                // 【情境 A】自訂名單模式
                // 只檢查「原本就在這個自訂名單內」的人
                // 這樣計算差集時，只會算出「這個名單裡缺席的人」，不會波及全教會
                $sqlCheck .= " AND member_id IN (SELECT member_id FROM custom_group_members WHERE group_id = ?)";
                $paramsCheck[] = $customGroupId;
                $scopeFound = true;

            } elseif ($targetRegionId) {
                // 【情境 B】小區模式
                // 維持原有邏輯，只鎖定該小區
                $sqlCheck .= " AND region_id = ?";
                $paramsCheck[] = $targetRegionId;
                $scopeFound = true;
            }

            // ★ 3. 安全防護：只有在「有明確範圍」時才去撈舊資料
            if ($scopeFound) {
                $stmtCheck = $this->conn->prepare($sqlCheck);
                $stmtCheck->execute($paramsCheck);
                $existingIds = $stmtCheck->fetchAll(PDO::FETCH_COLUMN, 0); 
                $existingIds = array_map('intval', $existingIds);
            } else {
                // 【情境 C】無範圍模式 (安全防護)
                // 如果前端沒傳小區也沒傳名單 ID，我們強制假設 existingIds 為空。
                // 結果：$cancelledIds = [] (沒有人會被刪除)
                // 結果：$addedIds = 所有上傳的人 (變成「只增不減」的安全模式)
                error_log("[Attendance] 警告：未指定範圍，啟動安全模式 (不執行刪除)");
                $existingIds = [];
            }

            // 計算差集：
            // cancelledIds: 原本有來($existingIds) 但 這次沒出現($newMemberIds) 的人
            // 由於上面有範圍鎖定，$existingIds 只會包含「範圍內」的人，所以這裡的刪除也是安全的。
            $cancelledIds = array_diff($existingIds, $newMemberIds);
            
            // addedIds: 這次新勾選的人
            $addedIds = array_diff($newMemberIds, $existingIds);

        } catch (Exception $e) {
            error_log("[Attendance] 讀取舊名單失敗: " . $e->getMessage());
        }

        // =========================================================
        // Step B: 本地資料庫更新 (這裡邏輯不變)
        // =========================================================
        try {
            if (method_exists($this->conn, 'beginTransaction')) {
                $this->conn->beginTransaction();
            }

            // 1. 執行「取消」
            if (!empty($cancelledIds)) {
                $placeholders = implode(',', array_fill(0, count($cancelledIds), '?'));
                $sqlUpdate = "UPDATE attendance_records 
                              SET status = NULL, synced = 0, updated_at = NOW() 
                              WHERE date = ? AND item_id = ? 
                              AND member_id IN ($placeholders)";
                $params = array_merge([$date, $meetingType], $cancelledIds);
                $this->conn->prepare($sqlUpdate)->execute($params);
            }

            // 2. 執行「新增」
            if (!empty($newMemberIds)) {
                $placeholders = implode(',', array_fill(0, count($newMemberIds), '?'));
                $sqlDetails = "SELECT member_id, group_id, region_id, category 
                               FROM members WHERE member_id IN ($placeholders)";
                $stmtDetails = $this->conn->prepare($sqlDetails);
                $stmtDetails->execute($newMemberIds);
                
                $memberInfos = [];
                while ($row = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                    $memberInfos[$row['member_id']] = $row;
                }

                $sqlInsert = "INSERT INTO attendance_records 
                              (member_id, item_id, date, year, week, status, district_id, group_id, region_id, category, created_at, synced) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
                              ON DUPLICATE KEY UPDATE 
                              status = 1, synced = 0, updated_at = NOW()"; 

                $stmtInsert = $this->conn->prepare($sqlInsert);

                foreach ($newMemberIds as $id) {
                    $info = $memberInfos[$id] ?? [];
                    $stmtInsert->execute([
                        $id, $meetingType, $date, $year, $week, 1, CHURCHID, 
                        $info['group_id'] ?? null, $info['region_id'] ?? null, $info['category'] ?? null
                    ]);
                }
            }

            if (method_exists($this->conn, 'commit')) {
                $this->conn->commit();
            }

        } catch (Exception $e) {
            if (method_exists($this->conn, 'rollBack')) { $this->conn->rollBack(); }
            return ["status" => "error", "message" => "本地寫入失敗"];
        }

        // =========================================================
        // Step C (極速版): 改為非同步處理
        // =========================================================
        
        // 1. 觸發背景程式 (Fire and Forget)
        // 讓 sync_runner.php 在後台慢慢處理上傳，不卡住使用者的畫面
        $this->triggerBackgroundSync();

        // 2. 直接回傳成功 (前端 0.1 秒內就會收到回應)
        return [
            "status" => "success", 
            "message" => "點名已儲存 (系統將在背景自動同步)"
        ];
    }

    // ★ 新增：觸發背景同步的輔助函式
    private function triggerBackgroundSync() {
        // 取得 sync_runner.php 的絕對路徑
        $runnerPath = __DIR__ . '/../sync_runner.php'; 
        
        if (file_exists($runnerPath)) {
            // 判斷作業系統，使用不同的指令丟入背景執行
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows: 使用 start /B
                pclose(popen("start /B php " . escapeshellarg($runnerPath), "r"));
            } else {
                // Linux/Mac: 使用 > /dev/null 2>&1 &
                // 這是最標準的背景執行方式，不會等待程式跑完
                exec("php " . escapeshellarg($runnerPath) . " > /dev/null 2>&1 &");
            }
        } else {
            error_log("[AttendanceService] 找不到背景程式: $runnerPath");
        }
    }

    // 輔助函式：發送 curl 請求
    public function sendToCentral($url, $postData, $cookieFile) {
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

        // 🛡️ 第一道防線：網路層檢查 (必須是 HTTP 200 且有回傳內容)
        if ($httpCode !== 200 || !$response) {
            return false; 
        }

        // 🛡️ 第二道防線：業務邏輯檢查 (解析 JSON 內容)
        $resData = json_decode($response, true);
        
        // 如果中央系統回傳的不是 JSON，或者 JSON 顯示失敗
        // 假設中央系統成功會回傳 {"status": "success"} 或類似結構
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 若中央系統不回傳 JSON，僅能依賴 HTTP 200 (維持現狀)
            return true; 
        }

        // ★ 關鍵：檢查中央系統回傳的成功標記 (請根據實際 API 欄位修改)
        if (isset($resData['status']) && $resData['status'] !== 'success') {
            error_log("[Central Error] " . ($resData['message'] ?? '未知錯誤'));
            return false; // 雖然連線成功，但中央系統沒處理成功
        }

        return true; // 真正意義上的同步成功
    }
}
?>