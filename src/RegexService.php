<?php
// src/RegexService.php

class RegexService {

    public function parseStats($text) {
        $results = [];
        
        // 1. 嘗試抓取日期 (例如 9/15-9/21)
        // 格式：數字/數字 (可能包含 -數字/數字)
        $week = '';
        if (preg_match('/(\d{1,2}\/\d{1,2}(?:-\d{1,2}\/\d{1,2})?)/', $text, $dateMatches)) {
            $week = $dateMatches[1];
        }

        // 2. 核心正則表達式
        // (?P<name>...) 抓取小區名稱 (非換行符號的任意字元)
        // 接著比對 "聖徒" ... 冒號 ... 數字
        // 支援全形冒號 (：) 和半形冒號 (:)
        $pattern = '/(?P<name>^[^\n]+)\n\s*聖徒.*?[：:]\s*(?P<saint>\d+)\s*\n\s*福音.*?[：:]\s*(?P<gospel>\d+)\s*\n\s*新接觸.*?[：:]\s*(?P<new>\d+)/mu';

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = trim($match['name']);

            // 排除掉可能是日期的行 (如果名稱長得像 9/15-9/21 就跳過)
            if (preg_match('/^\d+[\/-]\d+/', $name)) {
                continue;
            }

            // 嘗試推斷大區 (簡單邏輯：如果名稱包含"大區"，就當作大區名，但這裡先單純存入 sub_district)
            // 如果需要更複雜的「大區 -> 小區」繼承邏輯，可以在這裡擴充
            
            $results[] = [
                'week' => $week,
                'main_district' => '', // 正則較難跨行抓取上下文的大區，暫時留空
                'sub_district' => $name,
                'saint' => (int)$match['saint'],
                'gospel' => (int)$match['gospel'],
                'new' => (int)$match['new']
            ];
        }

        return $results;
    }
}
?>