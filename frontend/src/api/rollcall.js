// 設定後端 API 網址 (開發時可指向您的本地 PHP 伺服器)
// 若您發布到伺服器，這裡要改成伺服器的網址
const API_BASE = import.meta.env.VITE_API_URL || "https://www.citcnew.org.tw/churchstatshelper/api.php";

/**
 * 抓取本地名單 (整合 local_members)
 */
export async function fetchMembers(meeting, date) {
  // 對應 api.php?path=local-members
  const url = `${API_BASE}?path=local-members&district=永和&item_id=${meeting}&date=${date}`;
  const res = await fetch(url);
  const data = await res.json();
  
  if (data.status !== "success") throw new Error(data.message || "載入失敗");
  return data.members || [];
}

/**
 * 送出點名 (整合 attendance_submit)
 */
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

/**
 * 取得驗證碼圖片
 */
export async function fetchCaptcha() {
  const res = await fetch(`${API_BASE}?path=central-verify`);
  return res.json();
}

/**
 * 執行中央登入
 */
export async function loginCentral(picID, verifyCode) {
  const res = await fetch(`${API_BASE}?path=central-login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ picID, verifyCode })
  });
  return res.json();
}

/**
 * 檢查登入狀態
 */
export async function checkSession() {
  const res = await fetch(`${API_BASE}?path=central-session`);
  return res.json();
}