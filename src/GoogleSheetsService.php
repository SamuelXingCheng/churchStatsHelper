<?php
// src/GoogleSheetsService.php - 智慧模糊匹配版

$baseDir = dirname(dirname(__FILE__));
require_once $baseDir . '/vendor/autoload.php';
require_once __DIR__ . '/../config.php';

class GoogleSheetsService {
    private $service;
    private $spreadsheetId;
    private $tabName;

    public function __construct() {
        if (!class_exists('Google\Client')) {
            throw new Exception("Google API client not loaded.");
        }
        
        $client = new Google\Client();
        $client->setApplicationName('ChurchStatsHelper');
        $client->setScopes([Google\Service\Sheets::SPREADSHEETS]);
        $client->setAuthConfig(GOOGLE_APPLICATION_CREDENTIALS); 
        $client->setAccessType('offline');

        $this->service = new Google\Service\Sheets($client);
        $this->spreadsheetId = SPREADSHEET_ID;
        $this->tabName = SPREADSHEET_TAB_NAME;
    }

    private function numToColumn($num) {
        $string = '';
        while ($num > 0) {
            $num--;
            $string = chr(65 + ($num % 26)) . $string;
            $num = floor($num / 26);
        }
        return $string;
    }

    public function appendStats(array $statsData) {
        error_log("[Sheets] INFO: Redirecting appendStats to updateStatsForWeek.");
        return $this->updateStatsForWeek($statsData);
    }

    public function updateStatsForWeek(array $statsData) {
        if (empty($statsData)) return true;
        
        error_log("[Sheets DEBUG] --- 開始 Google Sheets 更新流程 ---");

        // 1. 讀取表格資料
        $range = $this->tabName . '!A1:AZ100';
        try {
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
            $sheetValues = $response->getValues();
        } catch (Exception $e) {
            error_log("[Sheets] ERROR reading sheet values: " . $e->getMessage());
            return false;
        }

        // 2. 建立日期映射表
        $dateColumnMap = [];
        $headerRow = $sheetValues[0] ?? [];
        error_log("[Sheets DEBUG] 正在讀取試算表標頭 (Row 1)...");
        foreach ($headerRow as $colIndex => $header) {
            $cleanedHeader = preg_replace('/\s+/', '', trim($header)); 
            if (preg_match('/[\d\/-]+/', $cleanedHeader)) {
                 $dateColumnMap[$cleanedHeader] = $colIndex + 1; 
                 // 🟢 印出找到的日期欄位
                 error_log("[Sheets DEBUG] >> 發現日期欄位: [{$cleanedHeader}] 在第 " . ($colIndex + 1) . " 欄");
            }
        }
        
        // 3. 建立群組映射表
        $groupRowMap = [];
        $GROUP_NAME_COLUMN_INDEX = 3; // D 欄
        error_log("[Sheets DEBUG] 正在讀取群組名稱 (Col D)...");
        foreach ($sheetValues as $rowIndex => $row) {
            $rawGroupName = $row[$GROUP_NAME_COLUMN_INDEX] ?? ''; 
            $groupName = preg_replace('/\s+/', '', trim($rawGroupName)); 
            if (!empty($groupName) && $rowIndex >= 2) { 
                $groupRowMap[$groupName] = $rowIndex + 1; 
            }
        }
        error_log("[Sheets DEBUG] >> 總共讀取到 " . count($groupRowMap) . " 個群組名稱。");
        
        // 4. 開始處理每一筆資料
        $updateRequests = [];

        foreach ($statsData as $index => $item) {
            $inputName = preg_replace('/\s+/', '', $item['sub_district']);
            $inputDate = $item['week'];

            error_log("--------------------------------------------------");
            error_log("[Sheets DEBUG] 正在處理第 " . ($index + 1) . " 筆資料:");
            error_log("[Sheets DEBUG] 輸入名稱: [{$inputName}]");
            error_log("[Sheets DEBUG] 輸入日期: [{$inputDate}]");
            
            $targetRow = null; 
            $startColIndex = null; 

            // === A. 名稱比對詳細流程 ===
            // 1. 完全匹配
            if (isset($groupRowMap[$inputName])) {
                $targetRow = $groupRowMap[$inputName];
                error_log("[Sheets DEBUG] (名稱) ✅ 完全匹配成功! Row: {$targetRow}");
            } else {
                error_log("[Sheets DEBUG] (名稱) ❌ 完全匹配失敗，嘗試模糊搜尋...");
                
                // 2. 括號關鍵字搜尋
                if (preg_match('/[\(（](.*?)[\)）]/u', $inputName, $matches)) {
                    $keyword = $matches[1];
                    error_log("[Sheets DEBUG] (名稱) >> 提取括號關鍵字: [{$keyword}]");
                    
                    foreach ($groupRowMap as $sheetName => $rowIndex) {
                        // 印出正在比對的過程 (為了避免 log 太多，只印出包含關鍵字的)
                        if (mb_strpos($sheetName, $keyword) !== false) {
                            $targetRow = $rowIndex;
                            error_log("[Sheets DEBUG] (名稱) ✅ 模糊匹配成功! 試算表名稱 [{$sheetName}] 包含關鍵字 [{$keyword}] -> Row: {$targetRow}");
                            break;
                        }
                    }
                    
                    if (!$targetRow) {
                        error_log("[Sheets DEBUG] (名稱) ❌ 遍歷所有名稱後，仍未找到包含 [{$keyword}] 的項目。");
                    }
                } else {
                    error_log("[Sheets DEBUG] (名稱) ❌ 輸入名稱中沒有括號，無法提取關鍵字。");
                }
            }

            // === B. 日期比對詳細流程 ===
            // 1. 完全匹配
            if (isset($dateColumnMap[$inputDate])) {
                $startColIndex = $dateColumnMap[$inputDate];
                error_log("[Sheets DEBUG] (日期) ✅ 完全匹配成功! Col: {$startColIndex}");
            } else {
                error_log("[Sheets DEBUG] (日期) ❌ 完全匹配失敗，嘗試區間搜尋...");
                
                if (preg_match('/^\d{1,2}\/\d{1,2}$/', $inputDate)) {
                     $inputMonthDay = explode('/', $inputDate);
                     $inputTimestamp = strtotime(date('Y') . '-' . $inputMonthDay[0] . '-' . $inputMonthDay[1]);
                     error_log("[Sheets DEBUG] (日期) >> 輸入日期轉為時間戳: " . date('Y-m-d', $inputTimestamp));

                     foreach ($dateColumnMap as $rangeKey => $colIndex) {
                         // 解析 12/8-14 或 11/24-30
                         if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:-(\d{1,2})(?:\/(\d{1,2}))?)?$/', $rangeKey, $rangeParts)) {
                             $startMonth = $rangeParts[1];
                             $startDay = $rangeParts[2];
                             // 處理跨月或同月結束日
                             $endMonth = !empty($rangeParts[4]) ? $rangeParts[3] : $startMonth;
                             $endDay = !empty($rangeParts[4]) ? $rangeParts[4] : $rangeParts[3];

                             $rangeStart = strtotime(date('Y') . '-' . $startMonth . '-' . $startDay);
                             $rangeEnd = strtotime(date('Y') . '-' . $endMonth . '-' . $endDay);

                             // 詳細比對日誌
                             // error_log("[Sheets DEBUG] (日期) 比對區間: [{$rangeKey}] ({$startMonth}/{$startDay} - {$endMonth}/{$endDay})");
                             
                             if ($inputTimestamp >= $rangeStart && $inputTimestamp <= $rangeEnd) {
                                 $startColIndex = $colIndex;
                                 error_log("[Sheets DEBUG] (日期) ✅ 區間匹配成功! 輸入日期在 [{$rangeKey}] 範圍內 -> Col: {$startColIndex}");
                                 break;
                             }
                         }
                     }
                     
                     if (!$startColIndex) {
                        error_log("[Sheets DEBUG] (日期) ❌ 遍歷所有日期區間後，無一符合。請檢查試算表標頭是否包含該日期。");
                     }
                } else {
                    error_log("[Sheets DEBUG] (日期) ❌ 輸入日期不是 '月/日' 格式，跳過區間搜尋。");
                }
            }

            // --- 最終檢查 ---
            if ($targetRow && $startColIndex) {
                // ... (準備寫入請求的邏輯) ...
                $valuesToWrite = [[$item['saint'] ?? 0, $item['gospel'] ?? 0, $item['new'] ?? 0]];
                
                // 轉換欄位字母方便人類閱讀 (例如 23 -> W)
                $startColLetter = $this->numToColumn($startColIndex);
                $rangeStr = $this->tabName . '!' . $startColLetter . $targetRow;
                
                $updateRequests[] = new Google\Service\Sheets\ValueRange([
                    'range' => $rangeStr . ':' . $this->numToColumn($startColIndex + 2) . $targetRow,
                    'values' => $valuesToWrite,
                ]);
                error_log("[Sheets DEBUG] 🎉 準備寫入: Range [{$rangeStr}] | Values: " . json_encode($valuesToWrite[0]));
            } else {
                error_log("[Sheets DEBUG] 💀 略過此筆資料: Row或Col未找到 (Row: " . ($targetRow ?? 'NULL') . ", Col: " . ($startColIndex ?? 'NULL') . ")");
            }
        }

        // 5. 執行批量更新
        if (!empty($updateRequests)) {
             try {
                $this->service->spreadsheets_values->batchUpdate($this->spreadsheetId, new Google\Service\Sheets\BatchUpdateValuesRequest([
                    'valueInputOption' => 'USER_ENTERED',
                    'data' => $updateRequests
                ]));
                error_log("[Sheets DEBUG] ✅ 批量更新成功! 共更新 " . count($updateRequests) . " 個範圍。");
            } catch (Exception $e) {
                error_log("[Sheets DEBUG] ❌ API 更新失敗: " . $e->getMessage());
            }
        } else {
            error_log("[Sheets DEBUG] ⚠️ 沒有任何有效的更新請求產生。");
        }

        return true;
    }
}