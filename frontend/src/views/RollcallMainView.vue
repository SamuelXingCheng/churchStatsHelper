<template>
  <div class="min-h-screen bg-gray-100 pb-28"> <div class="bg-white shadow-sm sticky top-0 z-10 border-b border-gray-200">
        <div class="max-w-md mx-auto px-4 h-14 flex justify-between items-center">
            <h1 class="font-bold text-lg text-gray-800 flex items-center gap-2">
                📋 點名小幫手
            </h1>
            
            <button 
                @click="checkLoginStatus"
                class="text-xs px-3 py-1.5 rounded-full transition-colors font-medium flex items-center gap-1 border"
                :class="loginSuccess ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100'"
            >
                <span class="w-2 h-2 rounded-full" :class="loginSuccess ? 'bg-green-500' : 'bg-gray-400'"></span>
                {{ loginSuccess ? '已連線中央' : '未連線 (點此登入)' }}
            </button>
        </div>
    </div>

    <div class="max-w-md mx-auto p-4 space-y-4">
        
        <RollcallFilterBar
            :selectedMeeting="selectedMeeting"
            :selectedDate="selectedDate"
            @update:meeting="selectedMeeting = $event"
            @update:date="selectedDate = $event"
        />

        <div class="bg-white rounded-xl p-4 shadow-sm min-h-[400px]">
            <div v-if="loadingMembers" class="flex flex-col items-center justify-center py-20 text-gray-400">
                <div class="animate-spin text-3xl mb-3">⏳</div>
                <p>正在載入名單...</p>
            </div>

            <div v-else-if="members.length > 0">
                <div class="grid grid-cols-3 gap-3">
                    <MemberCard
                        v-for="m in members"
                        :key="m.member_id"
                        :name="m.member_name"
                        :status="m.status"
                        :selected="selectedMembers.includes(m.member_id)"
                        @toggle="toggleSelect(m.member_id)"
                    />
                </div>
                <p class="text-center text-xs text-gray-400 mt-6">
                    共 {{ members.length }} 位成員
                </p>
            </div>

            <div v-else class="text-center py-20 text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
                <p class="text-lg mb-1">📭</p>
                <p>此日期/聚會尚無名單資料</p>
            </div>
        </div>
    </div>

    <div class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 p-4 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)] z-20">
        <div class="max-w-md mx-auto">
             <div class="flex justify-between items-center text-sm text-gray-500 mb-3 px-1">
                <span>
                    已選取: <b class="text-blue-600 text-lg mx-1">{{ selectedMembers.length }}</b> 人
                </span>
                <button v-if="selectedMembers.length" 
                        @click="selectedMembers = []" 
                        class="text-red-500 hover:text-red-700 text-xs px-2 py-1 bg-red-50 rounded">
                    清除選取
                </button>
            </div>

            <button
                class="w-full bg-blue-600 text-white py-3.5 rounded-xl font-bold text-lg shadow-lg hover:bg-blue-700 disabled:bg-gray-300 disabled:shadow-none disabled:cursor-not-allowed transition-all active:scale-[0.98] flex justify-center items-center"
                @click="handleSubmit"
                :disabled="selectedMembers.length === 0 || submitting"
            >
                <span v-if="submitting" class="animate-spin mr-2">⚪</span>
                {{ submitting ? '資料送出中...' : '送出點名' }}
            </button>

            <div v-if="submitMessage" class="mt-3 text-center text-sm font-medium rounded p-2 animate-bounce-in"
                :class="{
                    'bg-green-50 text-green-700 border border-green-100': submitSuccess === true,
                    'bg-orange-50 text-orange-600 border border-orange-100': submitSuccess === 'pending',
                    'bg-red-50 text-red-600 border border-red-100': submitSuccess === false
                }">
                {{ submitMessage }}
            </div>
        </div>
    </div>

    <RollcallLoginView
        v-if="showLoginModal"
        :captchaUrl="captchaUrl"
        :verifyCode="verifyCode"
        :loading="loggingIn"
        :captchaLoading="loadingCaptcha"
        @update:verifyCode="verifyCode = $event"
        @submitLogin="handleLogin"
        @loadCaptcha="loadCaptcha"
        @close="showLoginModal = false"
    />

  </div>
</template>

<script setup>
import { ref, onMounted, watch } from "vue"
// 引入我們做好的組件
import RollcallFilterBar from "../components/RollcallFilterBar.vue"
import MemberCard from "../components/MemberCard.vue"
import RollcallLoginView from "./RollcallLoginView.vue"
// 引入 API
import { fetchMembers, submitAttendance, checkSession, fetchCaptcha, loginCentral } from "../api/rollcall.js"
import { MEETINGS } from "../config/rollcallmeetings.js"

// --- 狀態變數 ---
const selectedMeeting = ref(MEETINGS.LORDSDAY) // 預設主日
const selectedDate = ref(new Date().toISOString().slice(0, 10))

const members = ref([])
const selectedMembers = ref([])
const loadingMembers = ref(false)

// 送出相關
const submitting = ref(false)
const submitMessage = ref("")
const submitSuccess = ref(false) // true | false | 'pending'

// 登入相關
const loginSuccess = ref(false)
const showLoginModal = ref(false)
const captchaUrl = ref("")
const picID = ref("")
const verifyCode = ref("")
const loadingCaptcha = ref(false)
const loggingIn = ref(false)

// --- 核心功能 ---

// 1. 載入名單
async function loadMembers() {
  loadingMembers.value = true
  submitMessage.value = "" 
  try {
    const data = await fetchMembers(selectedMeeting.value, selectedDate.value)
    // 確保 data 是陣列，避免 API 回傳錯誤結構導致崩潰
    members.value = Array.isArray(data) ? data : []
  } catch (err) {
    console.error("載入名單失敗：", err)
    members.value = []
  } finally {
    loadingMembers.value = false
  }
}

// 2. 點選/取消成員
function toggleSelect(memberId) {
  const idx = selectedMembers.value.indexOf(memberId)
  if (idx >= 0) {
    selectedMembers.value.splice(idx, 1)
  } else {
    selectedMembers.value.push(memberId)
  }
}

// 3. 送出點名 (整合您的舊邏輯)
async function handleSubmit() {
  submitting.value = true
  submitMessage.value = ""
  try {
    const result = await submitAttendance({
      district: "永和", // 這裡可視需求改為動態變數
      meeting_type: selectedMeeting.value,
      member_ids: selectedMembers.value,
      attend: 1,
      date: selectedDate.value
    })

    console.log("送出結果：", result)

    // 判斷回傳狀態 (相容舊專案的 status 判斷)
    if (result.status === "recorded" || result.status === "success") {
      // 成功情境
      if (loginSuccess.value && result.status === "success") {
        submitMessage.value = "✅ 點名成功！(中央已同步)"
        submitSuccess.value = true
      } else {
        // 未登入或同步失敗
        submitMessage.value = "⚠️ 已存本地，但中央未同步 (請檢查連線)"
        submitSuccess.value = "pending"
      }
      
      // 成功後清空選取並重整
      selectedMembers.value = []
      loadMembers() 
    } else {
      // API 回傳錯誤
      submitMessage.value = "注意：" + (result.message || "未知錯誤")
      submitSuccess.value = false
    }
  } catch (err) {
    submitMessage.value = "❌ 系統錯誤：" + err.message
    submitSuccess.value = false
  } finally {
    submitting.value = false
  }
}

// --- 登入控制邏輯 ---

// 檢查 Session (初始化時呼叫)
async function checkLoginStatus() {
    try {
        const res = await checkSession()
        if (res.loggedIn) {
            loginSuccess.value = true
        } else {
            loginSuccess.value = false
            // 若未登入，點擊按鈕時觸發開窗 & 載入驗證碼
            if (!showLoginModal.value) {
                showLoginModal.value = true
                loadCaptcha()
            }
        }
    } catch (e) {
        console.error("Session check failed", e)
    }
}

// 讀取驗證碼
async function loadCaptcha() {
    loadingCaptcha.value = true
    captchaUrl.value = ""
    try {
        const res = await fetchCaptcha()
        if (res.status === "success") {
            captchaUrl.value = res.url 
            picID.value = res.picID
        }
    } catch(e) {
        console.error("Captcha load failed", e)
    } finally {
        loadingCaptcha.value = false
    }
}

// 執行登入
async function handleLogin() {
    if (!verifyCode.value) return
    loggingIn.value = true
    try {
        const res = await loginCentral(picID.value, verifyCode.value)
        if (res.success) {
            loginSuccess.value = true
            showLoginModal.value = false
            verifyCode.value = ""
            // 登入成功後給個提示
            alert("🎉 登入成功！")
        } else {
            alert("❌ 登入失敗：" + res.message)
            verifyCode.value = ""
            loadCaptcha() // 失敗通常是因為驗證碼錯，直接換一張
        }
    } catch(e) {
        alert("系統錯誤")
    } finally {
        loggingIn.value = false
    }
}

// --- 生命週期與監聽 ---

// 當聚會類型或日期改變時，自動重新抓名單
watch([selectedMeeting, selectedDate], loadMembers)

onMounted(() => {
    loadMembers()
    // 初始不開窗，只檢查狀態
    checkSession().then(() => {
        // 如果您希望一進來若沒登入就自動跳窗，可在這裡將 showLoginModal.value = !loginSuccess.value
        // 目前設計為點擊按鈕才跳窗
        showLoginModal.value = false 
    })
})
</script>

<style scoped>
/* 簡單的彈入動畫 */
.animate-bounce-in {
  animation: bounceIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes bounceIn {
  0% { transform: scale(0.9); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
</style>