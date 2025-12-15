<?php
// src/RegexService.php - 最終去大區版

class RegexService {

    public function parseStats($text) {
        $results = [];
        
        // 1. 全域日期抓取
        $globalWeek = '';
        if (preg_match('/(\d{1,2}\/\d{1,2}(?:-\d{1,2}(?:\/\d{1,2})?)?)/', $text, $dateMatches)) {
            $globalWeek = $dateMatches[1];
        }

        // 2. 核心正則表達式 (抓取統計區塊)
        $pattern = '/
            (?P<name>[^\n]+)                               # 抓取名稱 (聖徒的前一行)
            (?:[\s\n]+(?:未回報|未開排|暫停|系統測試))?      # (容錯) 忽略狀態詞
            [\s\n]+                                        
            聖徒.*?[:：]\s*(?P<saint_raw>[^\n]*)            # 抓取聖徒數
            [\s\n]+
            福音.*?[:：]\s*(?P<gospel_raw>[^\n]*)           # 抓取福音數
            [\s\n]+
            (?:新接觸|來)三次以上.*?[:：]\s*(?P<new_raw>[^\n]*) # 抓取新接觸
        /mixu'; 

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        if (is_array($matches) && !empty($matches)) {
            foreach ($matches as $match) {
                // --- A. 名稱清洗 (關鍵修改) ---
                $name = trim($match['name']);

                // 1. 過濾 "總計"
                if (mb_strpos($name, '總計') !== false || mb_strpos($name, '總數') !== false) {
                    continue;
                }

                // 2. 過濾純日期行 (Regex 誤抓)
                if (preg_match('/^\d{1,2}\/\d{1,2}/', $name) && strlen($name) < 15) {
                     if (!preg_match('/[\x{4e00}-\x{9fa5}]/u', $name)) { // 如果不含中文
                         continue;
                     }
                }

                // 3. 移除開頭的日期 (例如 "12/11七大區..." -> "七大區...")
                $name = preg_replace('/^\d{1,2}\/\d{1,2}(?:-\d{1,2}(?:\/\d{1,2})?)?\s*/', '', $name);

                // 🚨 4. 【新增】剝離大區/小區前綴 🚨
                // 將 "七大區"、"六大區"、"中三區"、"十四大區" 等移除
                // 邏輯：移除開頭的 "數字/中文" + "大區/小區/區"
                // 例如： "七大區兒童排" -> "兒童排"
                //        "六大區\n旅順" -> (Regex只抓到旅順) -> "旅順" (沒事)
                //        "12/11七大區兒童排(泳在家)" -> (已去日期) "七大區兒童排(泳在家)" -> "兒童排(泳在家)"
                $name = preg_replace('/^[\x{4e00}-\x{9fa5}0-9]+[大小]?區\s*/u', '', $name);
                
                // 額外清理：如果移除後開頭還有 "-" 或 "_" 或空白
                $name = ltrim($name, "-_ \t\n\r\0\x0B");

                
                // --- B. 數字清洗 ---
                $saintCount = (int)trim($match['saint_raw']);
                $gospelCount = (int)trim($match['gospel_raw']);
                
                $rawNew = trim($match['new_raw']);
                if (preg_match('/^(\d+)/', $rawNew, $numMatch)) {
                    $newCount = (int)$numMatch[1];
                } else {
                    $newCount = $this->countNames($rawNew);
                }

                // --- C. 組裝 ---
                $results[] = [
                    'week' => $globalWeek,
                    'main_district' => '',
                    'sub_district' => $name, // 這裡已經是乾淨的排名稱 (例如 "兒童排(泳在家)")
                    'saint' => $saintCount,
                    'gospel' => $gospelCount,
                    'new' => $newCount 
                ];
            }
        }

        return $results;
    }

    private function countNames(string $input): int {
        $input = trim($input);
        if (is_numeric($input)) return (int)$input;
        $zeroKeywords = ['無', '0', '沒有', 'None', '未回報'];
        if (in_array($input, $zeroKeywords, true) || empty($input)) return 0;
        $separators = ['、', ' ', ',', '/', '，', '／'];
        $inputCleaned = str_replace($separators, ',', $input);
        $namesArray = array_filter(explode(',', $inputCleaned));
        $count = count($namesArray);
        if ($count === 1 && $inputCleaned === $input && $input !== '') return 1;
        return $count;
    }
}