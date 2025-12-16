<template>
  <div class="min-h-screen bg-gray-100 pt-20">
    <div class="min-h-screen bg-gray-100 flex items-center justify-center">
      <div class="bg-white shadow-lg rounded-xl p-6 w-full max-w-sm">

        <h2 class="text-xl font-bold mb-4 text-center">台中市召會輔助點名系統</h2>

        <div class="flex flex-col items-center space-y-4 mb-6">
          <div class="text-center text-sm"
              :class="loginSuccess ? 'text-green-600' : 'text-yellow-600'">
            {{ loginSuccess ? "🟢 已連線中央點名系統，點名將即時同步"
                            : "⚠️ 未連線中央點名系統，仍可點名，但非即時同步" }}
          </div>

          <div v-if="!loginSuccess" class="text-center">
            <button
              class="bg-yellow-500 text-white px-4 py-2 rounded-lg hover:bg-yellow-600"
              @click="showLoginModal = true"
            >
              連線中央點名系統
            </button>
          </div>
        </div>

        <RollcallMainView
          :loginSuccess="loginSuccess"
        />

        <div v-if="message" class="mt-4 text-center text-sm" :class="messageColor">
          {{ message }}
        </div>

        <RollcallLoginView
          v-if="showLoginModal"
          :captchaUrl="captchaUrl"
          :verifyCode="verifyCode"
          :loading="loading"
          :captchaLoading="captchaLoading"
          @update:verifyCode="verifyCode = $event"
          @submitLogin="submitLogin"
          @loadCaptcha="loadCaptcha" 
          @close="showLoginModal = false"
        />

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from "vue" // ★ 記得引入 watch
import liff from "@line/liff"
import RollcallLoginView from "./RollcallLoginView.vue"
import RollcallMainView from "./RollcallMainView.vue"

// ★ 修正 1: API_URL 必須包含 api.php，且確保資料夾大小寫正確
const API_URL = import.meta.env.VITE_ROLLCALL_API_URL || "https://www.citcnew.org.tw/churchStatsHelper/api.php"
const LIFF_ID = import.meta.env.VITE_ROLLCALL_LIFF_ID || "2008125912-zElwK0Ql"

// 狀態
const captchaUrl = ref("")
const picID = ref("")
const verifyCode = ref("")
const loading = ref(false)
const loginSuccess = ref(false)
const message = ref("")
const showLoginModal = ref(false)
const captchaLoading = ref(false)

const messageColor = computed(() =>
  message.value.includes("❌") ? "text-red-600" :
  message.value.includes("⚠️") ? "text-yellow-600" : "text-green-600"
)

// ★ 修正 2: 監聽 Modal 開啟狀態，一打開就載入驗證碼
watch(showLoginModal, (newVal) => {
  if (newVal === true) {
    loadCaptcha()
    verifyCode.value = "" // 清空輸入框
    message.value = ""    // 清空舊訊息
  }
})

// 初始化
onMounted(async () => {
  console.log("正在初始化 LIFF...")
  
  if (!LIFF_ID) {
    message.value = "❌ 系統錯誤：找不到 LIFF ID"
    return
  }

  try {
    await liff.init({ liffId: LIFF_ID })
    
    if (!liff.isLoggedIn()) {
      liff.login()
      return
    }
    checkSession()
    
  } catch (err) {
    message.value = "❌ LIFF 初始化失敗：" + err.message
    console.error("LIFF Init Error:", err)
  }
})

// 檢查 session 狀態
async function checkSession() {
  try {
    // API_URL 已經包含 api.php，這裡只要接 ?path=...
    const res = await fetch(`${API_URL}?path=central-session&ts=${Date.now()}`)
    const data = await res.json()
    loginSuccess.value = data.loggedIn
    if (data.loggedIn) {
        message.value = "✅ " + (data.message || "已登入")
    }
  } catch (err) {
    loginSuccess.value = false
    message.value = "❌ 檢查登入狀態失敗：" + err.message
  }
}

// 抓驗證碼
async function loadCaptcha() {
  captchaUrl.value = "" // 先清空，讓 UI 顯示 Loading 文字
  captchaLoading.value = true
  
  try {
    // 1. 請求 API 取得圖片網址
    const res = await fetch(`${API_URL}?path=central-verify&ts=${Date.now()}`)
    const data = await res.json()
    
    if (data.status === 'error') {
        throw new Error(data.message)
    }

    picID.value = data.picID
    
    // ★ 修正 3: 在圖片網址後方加上時間戳記，強制瀏覽器刷新圖片
    if (data.url) {
        // 判斷原網址是否已經有 ?，決定要用 & 還是 ? 連接
        const separator = data.url.includes('?') ? '&' : '?'
        captchaUrl.value = `${data.url}${separator}t=${new Date().getTime()}`
    }

  } catch (err) {
    message.value = "❌ 無法載入驗證碼：" + err.message
    console.error(err)
  } finally {
    captchaLoading.value = false
  }
}

// 登入中央
async function submitLogin() {
  loading.value = true
  message.value = ""
  try {
    const res = await fetch(`${API_URL}?path=central-login`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ verifyCode: verifyCode.value, picID: picID.value })
    })
    const result = await res.json()
    
    if (result.success || result.status === "success") {
      loginSuccess.value = true
      message.value = "✅ 登入成功，可以同步中央"
      showLoginModal.value = false
      // 登入成功後，重新檢查一次 Session 確保狀態一致
      checkSession()
    } else {
      loginSuccess.value = false
      message.value = "❌ 登入失敗：" + (result.message || "請檢查驗證碼")
      loadCaptcha() // 失敗後自動刷新驗證碼
    }
  } catch (err) {
    loginSuccess.value = false
    message.value = "❌ 連線錯誤：" + err.message
    loadCaptcha()
  } finally {
    loading.value = false
  }
}
</script>