<template>
  <div class="min-h-screen bg-gray-100 pt-10 pb-10"> <div class="flex items-center justify-center px-4"> <div v-if="initLoading" class="text-center">
        <div class="text-xl font-bold text-gray-600 mb-2">正在載入使用者資訊...</div>
        <div class="text-sm text-gray-400">請稍候</div>
      </div>

      <RollcallProfileEdit 
        v-else-if="!isProfileComplete" 
        :lineUserId="lineUserId"
        :currentUser="userProfile"
        @saved="onProfileSaved"
      />

      <div v-else class="bg-white shadow-lg rounded-xl p-6 w-full max-w-sm">

        <h2 class="text-xl font-bold mb-4 text-center">台中市召會輔助點名系統</h2>
        
        <div class="text-center mb-4 text-sm text-gray-500">
          Hi, {{ userProfile.line_display_name }} ({{ userProfile.main_district }} / {{ userProfile.sub_district }})
          <button @click="isProfileComplete = false" class="text-blue-500 underline ml-2">修改</button>
        </div>

        <div class="flex flex-col items-center space-y-4 mb-6">
          <div class="text-center text-sm"
              :class="loginSuccess ? 'text-green-600' : 'text-yellow-600'">
            {{ loginSuccess ? "🟢 已連線中央點名系統" : "⚠️ 未連線中央點名系統" }}
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

        <RollcallMainView :loginSuccess="loginSuccess" />

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
import { ref, onMounted, computed, watch } from "vue"
import liff from "@line/liff"
import RollcallLoginView from "./RollcallLoginView.vue"
import RollcallMainView from "./RollcallMainView.vue"
import RollcallProfileEdit from "../components/RollcallProfileEdit.vue" // 引入新元件
import { syncUserProfile } from "../api/rollcall.js" // 引入 API

const API_URL = import.meta.env.VITE_ROLLCALL_API_URL || "https://www.citcnew.org.tw/churchStatsHelper/api.php"
const LIFF_ID = import.meta.env.VITE_ROLLCALL_LIFF_ID || "2008125912-zElwK0Ql"

// UI 狀態
const initLoading = ref(true)
const isProfileComplete = ref(false)
const userProfile = ref({}) // 儲存後端回傳的使用者資料
const lineUserId = ref("")

// 中央登入相關狀態
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

watch(showLoginModal, (newVal) => {
  if (newVal === true) {
    loadCaptcha()
    verifyCode.value = ""
    message.value = ""
  }
})

// 初始化流程
onMounted(async () => {
  console.log("正在初始化 LIFF...")
  
  try {
    await liff.init({ liffId: LIFF_ID })
    
    if (!liff.isLoggedIn()) {
      liff.login()
      return
    }

    // 1. 取得 Line 資料
    const profile = await liff.getProfile()
    lineUserId.value = profile.userId
    
    console.log("Line Login Success:", profile)

    // 2. 同步到後端資料庫
    const res = await syncUserProfile({
      line_user_id: profile.userId,
      line_display_name: profile.displayName
    })

    console.log("Backend Sync Result:", res)

    // 3. 處理同步結果
    if (res.status === 'success') {
      userProfile.value = res.user
      isProfileComplete.value = res.profileComplete // 如果大區小區都有，就是 true
    } else {
      throw new Error(res.message)
    }

    // 4. 檢查中央系統登入狀態 (原本的邏輯)
    checkSession()

  } catch (err) {
    message.value = "❌ 初始化失敗：" + err.message
    console.error("Init Error:", err)
  } finally {
    initLoading.value = false
  }
})

// 當使用者在編輯頁面儲存成功後觸發
function onProfileSaved(updatedData) {
  // 更新本地資料，切換畫面
  userProfile.value = { ...userProfile.value, ...updatedData }
  isProfileComplete.value = true
}

// 檢查 session 狀態
async function checkSession() {
  try {
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
  captchaUrl.value = "" 
  captchaLoading.value = true
  try {
    const res = await fetch(`${API_URL}?path=central-verify&ts=${Date.now()}`)
    const data = await res.json()
    if (data.status === 'error') throw new Error(data.message)
    picID.value = data.picID
    if (data.url) {
        const separator = data.url.includes('?') ? '&' : '?'
        captchaUrl.value = `${data.url}${separator}t=${new Date().getTime()}`
    }
  } catch (err) {
    message.value = "❌ 無法載入驗證碼：" + err.message
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
      checkSession()
    } else {
      loginSuccess.value = false
      message.value = "❌ 登入失敗：" + (result.message || "請檢查驗證碼")
      loadCaptcha() 
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