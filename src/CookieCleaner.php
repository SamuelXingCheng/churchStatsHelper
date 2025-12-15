<?php
// src/CookieCleaner.php

class CookieCleaner {
    private $cookiePath;
    private $expireSeconds;

    public function __construct($expireSeconds = 3600) {
        // 設定 Cookie 存放目錄 (在專案根目錄下的 cookie 資料夾)
        $this->cookiePath = __DIR__ . "/../cookie";
        $this->expireSeconds = $expireSeconds;

        if (!file_exists($this->cookiePath)) {
            mkdir($this->cookiePath, 0777, true);
        }
    }

    // 清理舊的驗證碼 cookie
    public function cleanPicCookies() {
        foreach (glob($this->cookiePath . "/picCookie_*.tmp") as $file) {
            if (filemtime($file) < time() - $this->expireSeconds) {
                @unlink($file);
            }
        }
    }

    // 清理過期的 central_cookie.tmp
    public function cleanCentralCookieIfExpired($response, $cookieFile) {
        if (strpos($response, "帳號/Account") !== false &&
            strpos($response, "驗證碼/Captcha") !== false) {
            if (file_exists($cookieFile)) {
                @unlink($cookieFile);
            }
            return true; // 已刪除
        }
        return false; // 還有效
    }

    public function getCookiePath() {
        return $this->cookiePath;
    }
}
?>