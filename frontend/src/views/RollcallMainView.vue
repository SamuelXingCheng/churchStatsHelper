<template>
  <div class="max-w-lg mx-auto bg-white min-h-screen shadow-lg flex flex-col">
    <header class="flex justify-between items-center p-4 border-b bg-white sticky top-0 z-10">
      <h1 class="text-xl font-bold text-gray-800">📋 點名小幫手</h1>
      <button 
        @click="checkLoginStatus"
        class="text-sm px-3 py-1 rounded-full transition-colors"
        :class="loginSuccess ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
      >
        <span v-if="loginSuccess">✅ 已連線</span>
        <span v-else>☁️ 未連線 (點此登入)</span>
      </button>
    </header>

    <div class="p-4 flex-1 overflow-y-auto">
      <RollcallFilterBar
        :selectedMeeting="selectedMeeting"
        :selectedDate="selectedDate"
        @update:meeting="selectedMeeting = $event"
        @update:date="selectedDate = $event"
      />

      <div v-if="loadingMembers" class="py-10 text-center text-gray-500">
        載入中...
      </div>
      
      <div v-else class="grid grid-cols-3 gap-3">
        <MemberCard
          v-for="m in members"
          :key="m.member_id"
          :name="m.member_name"
          :status="m.status"
          :selected="selectedMembers.includes(m.member_id)"
          @toggle="toggleSelect(m.member_id)"
        />
      </div>
    </div>

    <div class="p-4 border-t bg-gray-50 sticky bottom-0">
      <div class="flex justify-between text-sm text-gray-500 mb-2 px-1">
        <span>已選: {{ selectedMembers.length }} 人</span>
        <button v-if="selectedMembers.length" @click="selectedMembers = []" class="text-red-500">清除</button>
      </div>
      <button
        class="w-full bg-blue-600 text-white py-3 rounded-xl font-bold text-lg shadow-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
        @click="handleSubmit"
        :disabled="selectedMembers.length === 0 || submitting"
      >
        {{ submitting ? '處理中...' : '送出點名' }}
      </button>
      <div v-if="submitMessage" class="mt-2 text-center text-sm font-medium" :class="messageClass">
        {{ submitMessage }}
      </div>
    </div>

    <RollcallLoginView
      v-if="showLogin"
      :captchaUrl="captchaUrl"
      :loading="loggingIn"
      :loadingCaptcha="loadingCaptcha"
      @close="showLogin = false"
      @refreshCaptcha="loadCaptcha"
      @submit="handleLogin"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from "vue";
import RollcallFilterBar from "../components/RollcallFilterBar.vue";
import MemberCard from "../components/MemberCard.vue";
import RollcallLoginView from "./RollcallLoginView.vue";
import { fetchMembers, submitAttendance, fetchCaptcha, loginCentral, checkSession } from "../api/rollcall.js";
import { MEETINGS } from "../config/rollcallmeetings.js";

const selectedMeeting = ref(MEETINGS.LORDSDAY);
const selectedDate = ref(new Date().toISOString().slice(0, 10));
const members = ref([]);
const selectedMembers = ref([]);
const loadingMembers = ref(false);
const submitting = ref(false);
const submitMessage = ref("");
const submitSuccess = ref(false); // true | false | 'pending'

// 登入相關
const showLogin = ref(false);
const loginSuccess = ref(false);
const captchaUrl = ref("");
const picID = ref("");
const loggingIn = ref(false);
const loadingCaptcha = ref(false);

const messageClass = computed(() => {
  if (submitSuccess.value === true) return "text-green-600";
  if (submitSuccess.value === 'pending') return "text-yellow-600";
  return "text-red-600";
});

async function loadMembers() {
  loadingMembers.value = true;
  try {
    members.value = await fetchMembers(selectedMeeting.value, selectedDate.value);
  } catch (err) {
    console.error(err);
    alert("載入名單失敗");
  } finally {
    loadingMembers.value = false;
  }
}

function toggleSelect(id) {
  const idx = selectedMembers.value.indexOf(id);
  if (idx >= 0) selectedMembers.value.splice(idx, 1);
  else selectedMembers.value.push(id);
}

async function handleSubmit() {
  submitting.value = true;
  submitMessage.value = "";
  try {
    const res = await submitAttendance({
      district: "永和",
      meeting_type: selectedMeeting.value,
      member_ids: selectedMembers.value,
      date: selectedDate.value
    });
    
    if (res.status === "success") {
      submitMessage.value = "✅ 點名成功！(中央已同步)";
      submitSuccess.value = true;
      loadMembers(); // 重新整理狀態
      selectedMembers.value = [];
    } else if (res.status === "pending") {
      submitMessage.value = "⚠️ 點名已存本地，但中央未同步 (請確認連線)";
      submitSuccess.value = 'pending';
    } else {
      submitMessage.value = "❌ " + (res.message || "未知錯誤");
      submitSuccess.value = false;
    }
  } catch (err) {
    submitMessage.value = "❌ 系統錯誤: " + err.message;
    submitSuccess.value = false;
  } finally {
    submitting.value = false;
  }
}

// 登入邏輯
async function checkLoginStatus() {
  const res = await checkSession();
  if (res.loggedIn) {
    loginSuccess.value = true;
  } else {
    loginSuccess.value = false;
    showLogin.value = true;
    loadCaptcha();
  }
}

async function loadCaptcha() {
  loadingCaptcha.value = true;
  const res = await fetchCaptcha();
  if (res.status === "success") {
    // 這裡要注意：如果您的後端在不同網域或子目錄，圖片 URL 可能要修正
    // 假設 api.php 回傳 "./pic/..."，前端需補上後端 Base URL
    // 簡單解法：讓後端回傳完整 URL，或前端處理
    // 這裡假設後端回傳相對路徑，我們需看您部署結構。開發環境可能需要調整。
    // 暫時直接使用 res.url
    captchaUrl.value = res.url; 
    picID.value = res.picID;
  }
  loadingCaptcha.value = false;
}

async function handleLogin(code) {
  loggingIn.value = true;
  const res = await loginCentral(picID.value, code);
  loggingIn.value = false;
  if (res.success) {
    showLogin.value = false;
    loginSuccess.value = true;
    alert("登入成功！");
  } else {
    alert("登入失敗：" + res.message);
    loadCaptcha(); // 失敗換一張
  }
}

watch([selectedMeeting, selectedDate], loadMembers);
onMounted(() => {
  loadMembers();
  checkSession().then(res => loginSuccess.value = res.loggedIn);
});
</script>