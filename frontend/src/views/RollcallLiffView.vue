<template>
  <div class="min-h-screen bg-navy-base flex flex-col font-sans text-gray-200 selection:bg-accent-gold selection:text-navy-base pb-32">
    
    <div v-if="!initLoading && isProfileComplete" class="sticky top-6 z-40 flex justify-center w-full px-4">
      <nav class="w-full max-w-[380px] bg-navy-base/80 backdrop-blur-md border border-white/10 shadow-xl shadow-black/20 rounded-2xl px-4 py-3 flex justify-between items-center transition-all duration-300">
        
        <div class="flex items-center space-x-3">
          <div class="bg-gradient-to-br from-blue-600/80 to-navy-light text-white font-bold rounded-xl w-10 h-10 flex items-center justify-center text-sm shadow-inner border border-white/10">
            {{ userProfile.main_district ? userProfile.main_district[0] : '召' }}
          </div>
          <div class="leading-tight">
            <div class="font-bold text-sm text-gray-100 tracking-wide">點名助手</div>
            <div class="text-[11px] text-gray-400 font-medium tracking-wider">
              {{ userProfile.line_display_name }}
            </div>
          </div>
        </div>

        <div class="flex items-center space-x-2">
          <button 
            @click="checkSession(true)" 
            class="p-2 rounded-full text-gray-400 hover:text-white hover:bg-white/5 transition active:scale-95"
            :class="{'animate-spin text-accent-gold': syncing}"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </button>

          <button @click="openProfileEdit" class="relative group">
            <div class="absolute inset-0 rounded-full border border-accent-gold/30 scale-110 opacity-0 group-hover:opacity-100 transition"></div>
            <img 
              :src="userProfile.pictureUrl || 'https://via.placeholder.com/150'" 
              class="h-8 w-8 rounded-full bg-gray-700 object-cover border border-white/10 shadow-lg" 
            />
          </button>
        </div>
      </nav>
    </div>

    <main class="flex-grow w-full max-w-[380px] mx-auto px-4 pt-10">
      <div v-if="initLoading" class="flex flex-col items-center justify-center h-[60vh] text-gray-500">
        <div class="animate-spin rounded-full h-8 w-8 border-2 border-gray-600 border-t-accent-gold mb-4"></div>
        <p class="text-xs font-medium tracking-widest uppercase opacity-60">Loading...</p>
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

    <div v-if="message" class="fixed top-28 left-1/2 -translate-x-1/2 bg-navy-light/90 border border-white/10 text-gray-100 px-6 py-3 rounded-xl shadow-2xl transition-all duration-300 z-[60] flex items-center space-x-3 backdrop-blur-md">
      <span class="text-accent-gold">●</span>
      <span class="font-medium text-sm tracking-wide">{{ message }}</span>
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