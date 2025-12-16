<template>
  <div class="min-h-screen bg-[#172952] flex flex-col font-sans text-gray-100 selection:bg-blue-500 selection:text-white pb-32">
    
    <div v-if="!initLoading && isProfileComplete" class="sticky top-6 z-40 flex justify-center w-full">
      <nav class="w-[92%] max-w-[380px] bg-[#112041]/95 backdrop-blur-xl border border-blue-400/20 shadow-2xl shadow-black/40 rounded-full px-5 py-3 flex justify-between items-center transition-all duration-300">
        
        <div class="flex items-center space-x-3">
          <div class="bg-gradient-to-b from-blue-500 to-blue-700 text-white font-bold rounded-full w-9 h-9 flex items-center justify-center text-sm shadow-lg border border-blue-300/30">
            {{ userProfile.main_district ? userProfile.main_district[0] : '召' }}
          </div>
          <div class="leading-tight">
            <div class="font-bold text-sm text-gray-100 tracking-wide">點名助手</div>
            <div class="text-[10px] text-blue-300 font-medium tracking-wider uppercase scale-90 origin-left">
              {{ userProfile.line_display_name }}
            </div>
          </div>
        </div>

        <div class="flex items-center space-x-1 bg-[#0b1426]/50 rounded-full p-1 border border-white/5">
          <button 
            @click="checkSession(true)" 
            class="p-1.5 rounded-full text-gray-400 hover:bg-white/10 hover:text-white transition active:scale-90"
            :class="{'animate-spin text-blue-400': syncing}"
            title="同步中央資料"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </button>

          <button @click="openProfileEdit" class="relative group p-0.5">
            <img 
              :src="userProfile.pictureUrl || 'https://via.placeholder.com/150'" 
              class="h-7 w-7 rounded-full bg-gray-700 object-cover border border-white/20 group-hover:border-blue-400 transition" 
              @error="$event.target.src = 'https://via.placeholder.com/150'"
            />
          </button>
        </div>
      </nav>
    </div>

    <main class="flex-grow w-full max-w-[380px] mx-auto px-2 mt-6">
      
      <div v-if="initLoading" class="flex flex-col items-center justify-center h-[60vh] text-blue-200/50">
        <div class="animate-spin rounded-full h-10 w-10 border-[3px] border-blue-900 border-t-blue-400 mb-4"></div>
        <p class="text-xs font-medium tracking-widest uppercase">Loading...</p>
      </div>

      <RollcallProfileEdit 
        v-else-if="!isProfileComplete || showProfileModal" 
        :lineUserId="lineUserId"
        :currentUser="userProfile"
        :isModal="isProfileComplete" 
        @saved="onProfileSaved"
        @close="showProfileModal = false"
      />

      <RollcallMainView 
        v-else 
        :userProfile="userProfile" 
        :loginSuccess="loginSuccess" 
        @openLogin="showLoginModal = true"
      />
    </main>

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

    <div v-if="message" class="fixed top-28 left-1/2 -translate-x-1/2 bg-[#112041] border border-blue-500/50 text-white px-5 py-2.5 rounded-full shadow-2xl transition-all duration-300 z-[60] flex items-center space-x-3 min-w-[200px] justify-center backdrop-blur-md">
      <span class="text-lg">🔔</span>
      <span class="font-medium text-sm">{{ message }}</span>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from "vue"
import liff from "@line/liff"
import RollcallLoginView from "./RollcallLoginView.vue"
import RollcallMainView from "./RollcallMainView.vue"
import RollcallProfileEdit from "../components/RollcallProfileEdit.vue" 
import { syncUserProfile } from "../api/rollcall.js" 

// 環境變數
const API_URL = import.meta.env.VITE_API_URL || "https://www.citcnew.org.tw/churchStatsHelper/api.php"
const LIFF_ID = import.meta.env.VITE_LIFF_ID || "2008125912-zElwK0Ql"

// 狀態管理
const initLoading = ref(true)
const syncing = ref(false)
const isProfileComplete = ref(false)
const showProfileModal = ref(false) 
const userProfile = ref({})
const lineUserId = ref("")

// 中央登入相關
const captchaUrl = ref("")
const picID = ref("")
const verifyCode = ref("")
const loading = ref(false)
const loginSuccess = ref(false)
const message = ref("")
const showLoginModal = ref(false)
const captchaLoading = ref(false)

// 監聽登入視窗開啟
watch(showLoginModal, (newVal) => {
  if (newVal === true) {
    loadCaptcha()
    verifyCode.value = ""
    message.value = ""
  }
})

// 初始化
onMounted(async () => {
  try {
    await liff.init({ liffId: LIFF_ID })
    if (!liff.isLoggedIn()) {
      liff.login()
      return
    }

    const profile = await liff.getProfile()
    lineUserId.value = profile.userId
    userProfile.value.pictureUrl = profile.pictureUrl

    // 同步後端資料
    const res = await syncUserProfile({
      line_user_id: profile.userId,
      line_display_name: profile.displayName
    })

    if (res.status === 'success') {
      userProfile.value = { ...userProfile.value, ...res.user }
      isProfileComplete.value = res.profileComplete
    }

    checkSession() 

  } catch (err) {
    showMessage("初始化失敗：" + err.message)
  } finally {
    initLoading.value = false
  }
})

function openProfileEdit() {
  showProfileModal.value = true
}

function onProfileSaved(updatedData) {
  userProfile.value = { ...userProfile.value, ...updatedData }
  isProfileComplete.value = true
  showProfileModal.value = false
  showMessage("個人資料已更新")
}

function showMessage(msg) {
  message.value = msg
  setTimeout(() => message.value = "", 3000)
}

async function checkSession(isManual = false) {
  if (isManual) syncing.value = true
  try {
    const res = await fetch(`${API_URL}?path=central-session&ts=${Date.now()}`)
    const data = await res.json()
    loginSuccess.value = data.loggedIn
    if (isManual) showMessage(data.loggedIn ? "中央系統連線正常" : "未登入中央系統")
  } catch (err) {
    loginSuccess.value = false
    if (isManual) showMessage("同步失敗")
  } finally {
    if (isManual) setTimeout(() => syncing.value = false, 1000)
  }
}

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
    message.value = "無法載入驗證碼：" + err.message
  } finally {
    captchaLoading.value = false
  }
}

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
      showMessage("登入成功，可以同步中央")
      showLoginModal.value = false
      checkSession()
    } else {
      loginSuccess.value = false
      message.value = "登入失敗：" + (result.message || "請檢查驗證碼")
      loadCaptcha() 
    }
  } catch (err) {
    loginSuccess.value = false
    message.value = "連線錯誤：" + err.message
    loadCaptcha()
  } finally {
    loading.value = false
  }
}
</script>