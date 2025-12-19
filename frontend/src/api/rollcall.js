// 設定後端 API 網址 (開發時可指向您的本地 PHP 伺服器)
// 若您發布到伺服器，這裡要改成伺服器的網址
const API_BASE = import.meta.env.VITE_API_URL || "https://www.citcnew.org.tw/churchstatshelper/api.php";

export async function fetchMembers(meetingType, date, benchmarkMode = 'self') {
  // 將 benchmark_mode 加入 URL 參數
  const url = `${API_BASE}?path=local-members&item_id=${meetingType}&date=${date}&benchmark_mode=${benchmarkMode}`
  const res = await fetch(url)
  const json = await res.json()
  return json.members
}

export async function triggerCentralSync(district, date) {
  // 如果沒有 date，後端會預設用今天，但最好還是傳過去
  const dateParam = date ? `&date=${date}` : '';
  const url = `${API_BASE}?path=central-members&district=${district}${dateParam}`;
  const res = await fetch(url);
  return await res.json();
}

export async function submitAttendance(data) {
  const url = `${API_BASE}?path=attendance-submit`
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      // ★ 將 Key 改為 sub_district，值傳入小區名稱 (例如 'T4-1')
      sub_district: data.sub_district, 
      meeting_type: data.meeting_type,
      member_ids: data.member_ids, 
      attend: 1,
      date: data.date
    })
  })
  return await res.json()
}

// === 新增：中央系統登入相關 (對接 AttendanceService) ===

export async function fetchCaptcha() {
  const res = await fetch(`${API_BASE}?path=central-verify`);
  return res.json();
}

export async function loginCentral(picID, verifyCode) {
  const res = await fetch(`${API_BASE}?path=central-login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ picID, verifyCode })
  });
  return res.json();
}

export async function checkSession() {
  const res = await fetch(`${API_BASE}?path=central-session`);
  return res.json();
}

export async function syncUserProfile(payload) {
  // ✅ 正確寫法：資料 (payload) 必須放在 body 裡面
  const res = await fetch(`${API_BASE}?path=user-profile`, {
    method: "POST",
    headers: { 
        "Content-Type": "application/json" 
        // ❌ 絕對不能在這裡加像 "X-Data": JSON.stringify(payload) 這樣的東西
        // ❌ 也不能有 Authorization: "中文"
    },
    body: JSON.stringify(payload) // ✅ 中文放在這裡是安全的
  });
  return res.json();
}