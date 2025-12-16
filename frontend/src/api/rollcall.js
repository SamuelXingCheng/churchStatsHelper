// 設定後端 API 網址 (開發時可指向您的本地 PHP 伺服器)
// 若您發布到伺服器，這裡要改成伺服器的網址
const API_BASE = import.meta.env.VITE_API_URL || "https://www.citcnew.org.tw/churchstatshelper/api.php";

export async function fetchMembers(meeting, date) {
  // 對應 api.php?path=local-members
  const url = `${API_BASE}?path=local-members&district=永和&item_id=${meeting}&date=${date}`;
  const res = await fetch(url);
  const data = await res.json();
  
  if (data.status !== "success") {
    // 容錯：如果沒有 members 陣列，回傳空陣列
    return [];
  }
  return data.members || [];
}

export async function submitAttendance({ district, meeting_type, member_ids, attend = 1, date }) {
  const formData = new FormData();
  formData.append("district", district);
  formData.append("meeting_type", meeting_type);
  formData.append("attend", attend);
  formData.append("date", date);
  member_ids.forEach(id => formData.append("member_ids[]", id));

  // 對應 api.php?path=attendance-submit
  const res = await fetch(`${API_BASE}?path=attendance-submit`, {
    method: "POST",
    body: formData
  });
  return res.json();
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

/**
 * 同步或更新使用者資料
 * @param {Object} payload 
 * - 登入時: { line_user_id, line_display_name }
 * - 更新時: { line_user_id, main_district, sub_district, email }
 */
export async function syncUserProfile(payload) {
  // 注意：我們將資料放在 body 中 (JSON)，這樣可以安全傳輸中文，不會報錯
  const res = await fetch(`${API_BASE}?path=user-profile`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  return res.json();
}