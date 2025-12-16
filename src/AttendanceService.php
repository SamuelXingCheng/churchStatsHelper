<?php
    // src/AttendanceService.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Database.php';      // 維持你原本的 DB 類別
require_once __DIR__ . '/CookieCleaner.php';
require_once __DIR__ . '/CentralSyncService.php';

class AttendanceService {
    private $conn;
    private $cookiePath;
    // 定義統一的 User-Agent，確保所有請求被視為同一個瀏覽器 session
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct() {
        if (!defined('CHURCHGROUP') || !defined('CENTRAL_USERNAME')) {
            error_log("[AttendanceService] Warning: Missing env configuration.");
        }
        $this->conn = Database::getInstance()->getConnection();
        $this->cookiePath = __DIR__ . "/../cookie";
        if (!file_exists($this->cookiePath)) {
            mkdir($this->cookiePath, 0777, true);
        }
    }

    /**
     * 路由分發器：根據 path 呼叫對應功能
     */
    public function handleRequest($path) {
        try {
            switch ($path) {
                case "central-verify":
                    return $this->centralVerify();
                case "central-login":
                    return $this->centralLogin();
                case "central-session":
                    return $this->centralSession();
                case "central-members":
                    return $this->centralMembers();
                case "local-members":
                    return $this->localMembers();
                case "attendance-submit": // 統一 submit 入口
                case "central-attendance":
                    return $this->attendanceSubmit(); 
                default:
                    throw new Exception("Unknown path: $path");
            }
        } catch (Exception $e) {
            // 統一錯誤回傳格式
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    // 對應: central_verify.php
    private function centralVerify() {
        $cleaner = new CookieCleaner(3600);
        $cleaner->cleanPicCookies();

        $picID = rand();
        $cookieFile = $this->cookiePath . "/picCookie_" . $picID . ".tmp";
        $loginUrl  = "https://www.chlife-stat.org/login.php";
        $verifyUrl = "https://www.chlife-stat.org/lib/securimage/securimage_show.php";

        // Step 1: 建立 Session
        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // 加入 User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_exec($ch);
        curl_close($ch);

        // Step 2: 抓圖片
        $ch = curl_init($verifyUrl);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // 加入 User-Agent (保持一致)
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        $imageData = curl_exec($ch);
        curl_close($ch);

        // Step 3: 存圖
        $picPath = __DIR__ . "/../pic";
        if (!file_exists($picPath)) mkdir($picPath, 0777, true);
        
        $fileName = "pic_" . $picID . ".jpg";
        file_put_contents($picPath . "/" . $fileName, $imageData);

        $publicUrl = "./pic/" . $fileName; 

        return [
            "status"  => "success",
            "message" => "驗證碼圖片已存檔",
            "url"     => $publicUrl,
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
            "district"     => CHURCHGROUP,
            "church_id"    => CHURCHID,
            "account"      => CENTRAL_USERNAME,
            "pwd"          => CENTRAL_PASSWORD,
            "language"     => "zh-tw",
            "captcha_code" => $verifyCode
        ];

        $ch = curl_init("https://www.chlife-stat.org/authenticate.php");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // 使用統一的 User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.chlife-stat.org/login.php');

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

        $ch = curl_init("https://www.chlife-stat.org/index.php");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // 使用統一的 User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // 如果被轉址回 login.php，或是頁面含有登入框，代表 Session 失效
        if (strpos($effectiveUrl, 'login.php') !== false || strpos($response, "帳號/Account") !== false) {
            @unlink($cookieFile);
            return ["loggedIn" => false, "message" => "Session 已過期，請重新登入"];
        }

        return ["loggedIn" => true, "message" => "已登入"];
    }

    // 對應: central_members.php
    private function centralMembers() {
        
        // 1. 取得查詢參數，並設定預設值
        $district = $_GET['district'] ?? '永和'; 
        $search   = $_GET['search']   ?? '';
        error_log("District:".$district);
        error_log("search:".$search);

        // 2. 檢查 Cookie 檔案是否存在，用於維持登入狀態
        $cookieFile = $this->cookiePath . "/central_cookie.tmp";
        if (!file_exists($cookieFile)) {
            throw new Exception("Cookie 不存在，請先執行登入");
        }
    
        // 3. 取得當前年份與週次
        $year = date("Y");
        $week = date("W");
    
        // 4. 根據地區名稱 ($district) 取得對應的設定值 (configValue)
        // 假設 DISTRICT_ID 是一個定義好的常數或全域變數，存儲地區到 ID 的映射
        $districtMap = (defined('DISTRICT_ID') ? DISTRICT_ID : []);
        
        // ★★★ 關鍵修正：取得完整的設定值 (例如 '7586,3') ★★★
        $configValue = $districtMap[$district] ?? ''; 
    
        if (empty($configValue)) {
            throw new Exception("找不到對應的大區 ID 設定");
        }
    
        // 5. 建構用於抓取名單的 URL
        $url = "https://www.chlife-stat.org/list_members.php"
             . "?start=0&limit=2000&year=$year&week=$week" // 保持 limit=2000 以抓取完整名單
             . "&sex=&member_status=&status=&role="        // 新增的參數
             . "&search_col=member_name&search=" . urlencode($search)
             // 傳遞完整的編碼設定值，用於篩選大區/教會
             . "&churches%5B%5D=" . urlencode($configValue) 
             . "&filter_mode=churchStructureTab&roll_call_list="; // 新增的參數
    
        // 6. 初始化 cURL 請求
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); // 帶上登入 Cookie
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.chlife-stat.org/');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
        // 7. 執行 cURL 請求並取得結果
        $result = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
    
        // 8. 檢查是否因為 Session 失效被重導向到登入頁
        if (strpos($effectiveUrl, 'login.php') !== false) {
             @unlink($cookieFile);
             throw new Exception("Session 失效，請重新登入。");
        }
    
        // 9. 解析 JSON 數據
        $data = json_decode($result, true);
    
        if (!$data || !isset($data['members'])) {
            throw new Exception("中央回傳格式錯誤");
        }
    
        // 10. 啟用同步功能，將名單寫入本地資料庫
        // 假設 CentralSyncService 類別已經被引入
        $sync = new CentralSyncService();
        $sync->syncMembersAndAttendance($district, $data);
    
        // 11. 返回抓取到的數據
        return $data;
    }
    
    // 對應: local_members.php
    private function localMembers() {
        $itemId = $_GET['item_id'] ?? null;
        if (!$itemId) throw new Exception("缺少 item_id");

        $dateInput = $_GET['date'] ?? date("Y-m-d");
        $dateObj = new DateTime($dateInput);
        $dateObj->modify('Monday this week');
        $dateObj->modify('+6 days');
        $sundayDate = $dateObj->format('Y-m-d');

        $sql = "SELECT m.member_id, m.name, m.gender, m.group_id, m.region_id, m.category,
                       r.status, r.item_id AS record_item
                FROM members m
                LEFT JOIN attendance_records r
                  ON m.member_id = r.member_id AND r.date = ? AND r.item_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$sundayDate, $itemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $members = array_map(function ($row) use ($itemId) {
            return [
                "member_id"   => intval($row["member_id"]),
                "member_name" => $row["name"],
                "sex"         => $row["gender"],
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

        // 計算週次
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

        // ★★★ 修正 3：使用 http_build_query 組裝 POST 資料，更安全標準 ★★★
        $postData = [
            'meeting' => $meetingType,
            'year'    => $year,
            'week'    => $week,
            'attend'  => $attend,
            'member_ids' => $memberIds // cURL 會自動處理陣列變數 member_ids[]
        ];

        // 必須使用 http_build_query 來處理陣列結構，才能正確轉為 member_ids[]=1&member_ids[]=2
        $postString = http_build_query($postData);

        $url = "https://www.chlife-stat.org/edit_member_activity.php";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // 使用統一的 User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            // 更新本地 synced=1
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $updateSql = "UPDATE attendance_records SET synced=1, synced_at=NOW() 
                          WHERE member_id IN ($placeholders) AND item_id = ? AND date = ?";
            // 參數合併：memberIds + meetingType + date
            $params = array_merge($memberIds, [$meetingType, $date]);
            $this->conn->prepare($updateSql)->execute($params);

            return ["status" => "success", "message" => "點名成功，中央已同步"];
        } else {
            return ["status" => "pending", "message" => "中央同步失敗，HTTP $httpCode"];
        }
    }
}
?>