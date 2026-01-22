<template>
  <div class="min-h-screen bg-navy-base flex flex-col font-sans text-gray-200 selection:bg-accent-gold selection:text-navy-base pb-32">
    
    <div v-if="!initLoading && isProfileComplete" class="sticky top-6 z-40 flex justify-center w-full px-4">
      <nav class="w-full max-w-[600px] bg-navy-base/80 backdrop-blur-md border border-white/10 shadow-xl shadow-black/20 rounded-2xl px-4 py-3 flex justify-between items-center transition-all duration-300">
        
        <div class="flex items-center space-x-3">
          <div class="bg-gradient-to-br from-blue-600/80 to-navy-light text-white font-bold rounded-xl w-10 h-10 flex items-center justify-center text-sm shadow-inner border border-white/10 shrink-0">
            {{ userProfile.main_district ? userProfile.main_district[0] : '召' }}
          </div>
          <div class="leading-tight truncate">
            <div class="font-bold text-sm text-gray-100 tracking-wide">點名助手</div>
            <div class="text-[10px] text-gray-400 font-medium tracking-wider mt-0.5 truncate max-w-[80px]">
              {{ userProfile.line_display_name }}
            </div>
          </div>
        </div>

        <div class="flex items-center space-x-2">
          
          <button 
            v-if="loginSuccess"
            @click="handleSyncAll"
            :disabled="syncing"
            class="flex items-center space-x-1 px-2.5 py-1.5 rounded-lg text-[10px] font-bold border transition-all active:scale-95 whitespace-nowrap"
            :class="syncing 
              ? 'border-amber-500/50 text-amber-500 bg-amber-500/10 cursor-wait' 
              : 'border-amber-500/30 text-amber-500/90 hover:bg-amber-500/10 hover:border-amber-500/50'"
          >
            <svg v-if="syncing" class="animate-spin h-3 w-3" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <span>獲取全部正式資料</span>
          </button>

          <button 
            @click="checkSession(true)" 
            class="flex items-center space-x-1 px-2.5 py-1.5 rounded-lg text-[10px] font-bold border transition-all active:scale-95 whitespace-nowrap"
            :class="checking 
              ? 'border-green-500/50 text-green-400 bg-green-500/10' 
              : 'border-gray-600 text-gray-400 hover:border-gray-400 hover:text-gray-200'"
          >
            <svg v-if="checking" class="animate-pulse h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
            <span>檢查連線</span>
          </button>

          <button @click="openProfileEdit" class="relative group pl-1">
            <div class="absolute inset-0 rounded-full border border-accent-gold/30 scale-110 opacity-0 group-hover:opacity-100 transition"></div>
            <img 
              :src="userProfile.pictureUrl || 'https://via.placeholder.com/150'" 
              class="h-8 w-8 rounded-full bg-gray-700 object-cover border border-white/10 shadow-lg" 
            />
          </button>
        </div>
      </nav>
    </div>

    <main class="flex-grow w-full max-w-[600px] mx-auto px-4 pt-6">
      
      <div v-if="initLoading" class="flex flex-col items-center justify-center h-[60vh] text-gray-500">
        <div class="animate-spin rounded-full h-9 w-9 border-2 border-gray-600 border-t-accent-gold mb-4"></div>
        <p class="text-sm font-medium tracking-widest uppercase opacity-60">Loading...</p>
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
        ref="mainViewRef"
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

    <div v-if="message" class="fixed top-32 left-1/2 -translate-x-1/2 bg-navy-light/90 border border-white/10 text-gray-100 px-6 py-3 rounded-xl shadow-2xl transition-all duration-300 z-[60] flex items-center space-x-3 backdrop-blur-md min-w-[250px] justify-center">
      <span class="text-accent-gold text-lg">●</span>
      <span class="font-medium text-base tracking-wide">{{ message }}</span>
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
const syncing = ref(false) // 用於控制「獲取正式資料」按鈕的轉圈圈
const checking = ref(false) // 用於控制「檢查連線」按鈕的動畫
const isProfileComplete = ref(false)
const showProfileModal = ref(false) 
const userProfile = ref({})
const lineUserId = ref("")

// Template Ref: 用來抓取子元件
const mainViewRef = ref(null)

// 中央登入相關
const captchaUrl = ref("")
const picID = ref("")
const verifyCode = ref("")
const loading = ref(false)
const loginSuccess = ref(false)
const message = ref("")
const showLoginModal = ref(false)
const captchaLoading = ref(false)

watch(showLoginModal, (newVal) => {
  if (newVal === true) {
    loadCaptcha()
    verifyCode.value = ""
    message.value = ""
  }
})

onMounted(async () => {
  let isRedirecting = false;

  try {
    const timeoutPromise = new Promise((_, reject) => {
      setTimeout(() => reject(new Error("TIMEOUT")), 3000);
    });

    const liffInitPromise = liff.init({ liffId: LIFF_ID });
    await Promise.race([liffInitPromise, timeoutPromise]);

    if (!liff.isLoggedIn()) {
      liff.login({ redirectUri: window.location.href });
      return;
    }
    
    const profile = await liff.getProfile()
    lineUserId.value = profile.userId
    userProfile.value.pictureUrl = profile.pictureUrl

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
    console.error("LIFF Init Error:", err);
    if (err.message === "TIMEOUT") {
        const isLineBrowser = navigator.userAgent.includes('Line');
        if (isLineBrowser) {
            initLoading.value = true;
            showMessage("連線回應較慢，正在為您切換至順暢模式...");
            isRedirecting = true;
            window.location.replace(`https://liff.line.me/${LIFF_ID}?openExternalBrowser=1`);
            return; 
        }
    }
    showMessage("系統載入異常：" + err.message);
  } finally {
    if (!isRedirecting) {
        initLoading.value = false;
    }
  }
})

// 處理「獲取正式資料」點擊
async function handleSyncAll() {
  if (mainViewRef.value) {
    syncing.value = true; 
    try {
      // 呼叫子元件暴露出來的 performSync 函式 (同步全部模式)
      await mainViewRef.value.performSync(true, 'all');
      
      // ★★★ 新增這行：既然同步成功，代表連線絕對是正常的，直接轉綠燈 ★★★
      loginSuccess.value = true; 

    } catch (e) {
      console.error(e);
      // 如果同步失敗是因為 401 (權限不足)，那燈號會自動變紅 (在 mainView 的錯誤處理裡)
    } finally {
      syncing.value = false;
    }
  } else {
    showMessage("頁面尚未載入完成");
  }
}

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
  if (isManual) checking.value = true 
  try {
    const res = await fetch(`${API_URL}?path=central-session&ts=${Date.now()}`)
    const data = await res.json()
    loginSuccess.value = data.loggedIn
    if (isManual) showMessage(data.loggedIn ? "中央系統連線正常" : "未登入中央系統")
  } catch (err) {
    loginSuccess.value = false
    if (isManual) showMessage("連線失敗")
  } finally {
    if (isManual) setTimeout(() => checking.value = false, 1000)
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