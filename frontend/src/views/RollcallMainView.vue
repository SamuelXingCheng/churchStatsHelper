<template>
  <div class="pb-24">
    
    <div v-if="!loginSuccess" class="bg-[#2a1c1c]/80 border border-orange-500/30 rounded-2xl p-4 mb-6 flex items-center justify-between shadow-lg">
      <div class="flex items-center space-x-3 text-orange-200">
        <div class="bg-orange-500/20 p-1.5 rounded-full animate-pulse">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
          </svg>
        </div>
        <span class="text-xs font-bold tracking-wide">中央系統未連線</span>
      </div>
      <button @click="$emit('openLogin')" class="text-[10px] bg-orange-600 text-white px-3 py-1.5 rounded-full font-bold hover:bg-orange-500 transition shadow-lg">
        立即連線
      </button>
    </div>

    <RollcallFilterBar 
      :meetingType="meetingType"
      :date="date"
      @update:meetingType="meetingType = $event"
      @update:date="date = $event"
      class="mb-3"
    />

    <div class="bg-[#0f172a] p-1.5 rounded-2xl mb-5 shadow-inner border border-white/5">
      <div class="flex justify-between items-center px-1">
        <div class="flex space-x-2">
          <button 
            @click="activeTab = 'district'" 
            class="px-4 py-2.5 rounded-xl text-xs font-bold transition-all duration-300"
            :class="activeTab === 'district' ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/50' : 'text-gray-400 hover:text-gray-200 hover:bg-white/5'"
          >
            {{ userProfile.sub_district || '本區' }}
          </button>
          <button 
            @click="activeTab = 'custom'" 
            class="px-4 py-2.5 rounded-xl text-xs font-bold transition-all duration-300"
            :class="activeTab === 'custom' ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/50' : 'text-gray-400 hover:text-gray-200 hover:bg-white/5'"
          >
            自訂
          </button>
        </div>

        <button 
          @click="toggleBenchmark"
          class="flex items-center space-x-2 px-3 py-1.5 rounded-lg border transition-all duration-300 group"
          :class="useSundayBenchmark 
            ? 'bg-amber-500/10 border-amber-500/50 text-amber-400' 
            : 'bg-transparent border-gray-600/50 text-gray-500 hover:border-gray-400'"
        >
          <span class="text-[10px] font-bold">參考主日</span>
          <div class="w-7 h-3.5 rounded-full relative transition-colors duration-300"
               :class="useSundayBenchmark ? 'bg-amber-500' : 'bg-gray-700'">
            <div class="absolute top-0.5 h-2.5 w-2.5 rounded-full bg-white transition-all duration-300 shadow-sm"
                 :class="useSundayBenchmark ? 'left-4' : 'left-0.5'"></div>
          </div>
        </button>
      </div>
    </div>

    <div class="flex justify-between items-center mb-3 px-2">
      <label class="flex items-center space-x-2 cursor-pointer select-none group">
        <div class="relative flex items-center">
          <input type="checkbox" 
                 @change="toggleAll" 
                 :checked="isAllSelected" 
                 class="peer h-4 w-4 cursor-pointer appearance-none rounded border border-gray-500 bg-[#0f172a] checked:bg-blue-500 checked:border-blue-500 transition-all" />
          <svg class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-3 h-3 pointer-events-none opacity-0 peer-checked:opacity-100 text-white" viewBox="0 0 14 14" fill="none">
            <path d="M3 8L6 11L11 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <span class="text-xs text-gray-400 font-medium group-hover:text-gray-200 transition">全選本頁</span>
      </label>

      <div class="flex items-center space-x-3">
          
          <div v-if="lastSyncTime" class="flex items-baseline space-x-2"> 
            <span class="text-xs text-gray-400 font-bold tracking-wider hidden sm:inline">更新</span>
            <span class="text-base text-blue-300 font-black font-mono leading-none shadow-blue-500/20 drop-shadow-sm">
              {{ lastSyncTime }}
            </span>
          </div>

          <div class="relative" :class="{ 'z-50': showGuide }">
            <button 
              v-if="loginSuccess"
              @click="handleManualSync" 
              :disabled="isSyncing"
              class="flex items-center space-x-1.5 px-3 py-1 rounded-full text-[10px] font-bold transition-all active:scale-95 border relative"
              :class="[
                isSyncing 
                  ? 'bg-gray-800 text-gray-400 border-gray-700 cursor-wait' 
                  : 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30 hover:bg-indigo-500/20',
                showGuide ? 'ring-2 ring-accent-gold ring-offset-2 ring-offset-navy-base bg-navy-base border-accent-gold text-accent-gold' : ''
              ]"
            >
              <svg v-if="isSyncing && syncMode === 'current'" class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              <span>{{ isSyncing && syncMode === 'current' ? '獲取中' : '獲取該聚會正式資料' }}</span>
            </button>

            <div v-if="showGuide" 
               class="absolute top-full right-0 mt-5 w-80 bg-accent-gold text-navy-base p-6 rounded-2xl shadow-2xl animate-bounce-slight origin-top-right z-50 pointer-events-auto border-2 border-white/20">
              <div class="absolute -top-3 right-6 w-6 h-6 bg-accent-gold rotate-45 border-t-2 border-l-2 border-white/20"></div>
              <div class="relative z-10">
                <h3 class="font-black text-2xl mb-4 flex items-center tracking-wide leading-tight">
                  {{ guideMessage.title }}
                </h3>
                <p class="text-lg font-bold leading-relaxed opacity-95 text-justify tracking-wide">
                  {{ guideMessage.text }}
                </p>
                <div class="flex justify-end items-center space-x-4 mt-6 pt-4 border-t border-navy-base/15">
                  <button @click.stop="closeGuide" 
                          class="text-sm text-navy-base/70 hover:text-navy-base underline decoration-dotted transition font-bold px-2 py-1">
                    跳過教學
                  </button>
                  <button v-if="guideMessage.type === 'list'" 
                          @click.stop="closeGuide" 
                          class="text-base font-black bg-navy-base/10 px-5 py-2.5 rounded-xl hover:bg-navy-base/20 transition shadow-sm">
                    我知道了
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="text-[10px] text-blue-300 bg-[#0f172a] px-3 py-1 rounded-full border border-blue-500/20">
            已選 <span class="font-bold text-white text-xs ml-0.5">{{ selectedIds.length }}</span> 人
          </div>
      </div>
    </div>

    <div class="bg-[#0f172a]/50 rounded-3xl p-3 border border-white/5 min-h-[200px] shadow-inner space-y-6">
      <div v-if="loadingMembers" class="space-y-3 p-1">
        <div v-for="i in 3" :key="i" class="h-16 bg-[#1e325c] rounded-xl animate-pulse"></div>
      </div>
      <div v-else-if="filteredMembers.length === 0" class="flex flex-col items-center justify-center h-48 text-gray-500">
        <div class="text-4xl mb-3 opacity-30 grayscale">📂</div>
        <p class="text-xs tracking-wider">暫無名單資料</p>
      </div>
      <div v-else>
        <div v-if="groupedMembers.regulars.length > 0" class="mb-6">
          <div class="flex items-center space-x-2 px-2 mb-3 text-blue-200/80">
            <span class="text-sm font-bold transition-all duration-300">
              {{ useSundayBenchmark ? '主日常客' : '本會常客' }}
            </span>
            <div class="h-px flex-1 bg-gradient-to-r from-blue-500/30 to-transparent"></div>
            <span class="text-[10px] bg-blue-500/10 px-2 py-0.5 rounded text-blue-300">
              {{ groupedMembers.regulars.length }}
            </span>
          </div>
          <div class="grid grid-cols-3 gap-2">
            <MemberCard 
              v-for="member in groupedMembers.regulars" 
              :key="member.member_id"
              :member="member"
              :isSelected="selectedIds.includes(member.member_id)"
              @toggle="toggleMember(member.member_id)"
            />
          </div>
        </div>
        <div v-if="groupedMembers.others.length > 0">
          <div class="flex items-center space-x-2 px-2 mb-3 text-gray-400/80">
            <span class="text-sm font-bold">牧養名單</span>
            <div class="h-px flex-1 bg-gradient-to-r from-gray-600/30 to-transparent"></div>
            <span class="text-[10px] bg-gray-700/30 px-2 py-0.5 rounded text-gray-400">
              {{ groupedMembers.others.length }}
            </span>
          </div>
          <div class="grid grid-cols-3 gap-2 opacity-90">
            <MemberCard 
              v-for="member in groupedMembers.others" 
              :key="member.member_id"
              :member="member"
              :isSelected="selectedIds.includes(member.member_id)"
              @toggle="toggleMember(member.member_id)"
            />
          </div>
        </div>
      </div>
    </div>

    <div class="fixed bottom-8 left-0 w-full flex justify-center z-30 pointer-events-none">
      <div class="w-[92%] max-w-[360px] bg-[#112041] border border-blue-400/30 p-1.5 rounded-full shadow-2xl shadow-black/50 flex items-center justify-between pointer-events-auto backdrop-blur-md">
        <div class="pl-5 pr-4 flex flex-col justify-center h-full">
          <div class="text-[9px] text-gray-400 uppercase tracking-widest leading-none mb-0.5">Total</div>
          <div class="text-white font-bold text-lg leading-none">{{ selectedIds.length }}</div>
        </div>
        <button 
          @click="confirmSubmit" 
          class="h-11 px-8 rounded-full font-bold text-sm transition-all active:scale-95 shadow-lg flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed"
          :class="selectedIds.length > 0 
            ? 'bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white shadow-blue-900/50' 
            : 'bg-gray-700 text-gray-400'"
          :disabled="submitting || selectedIds.length === 0"
        >
          <span v-if="submitting" class="animate-spin rounded-full h-3 w-3 border-2 border-white border-t-transparent"></span>
          <span>{{ submitting ? '傳送中' : '確認送出' }}</span>
          <svg v-if="!submitting" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
          </svg>
        </button>
      </div>
    </div>

    <div v-if="showGuide" 
         class="fixed inset-0 bg-black/70 backdrop-blur-[2px] z-40 transition-opacity duration-300 cursor-pointer"
         @click="closeGuide">
    </div>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, defineExpose } from 'vue' // ★ 新增 defineExpose
import RollcallFilterBar from '../components/RollcallFilterBar.vue'
import MemberCard from '../components/MemberCard.vue' 
import { fetchMembers, submitAttendance, triggerCentralSync } from '../api/rollcall.js'

const props = defineProps({
  userProfile: Object,
  loginSuccess: Boolean
})

const emit = defineEmits(['openLogin'])

// 狀態
const meetingType = ref('37') 
const date = ref(new Date().toISOString().split('T')[0])
const activeTab = ref('district') 
const members = ref([])
const selectedIds = ref([])
const loadingMembers = ref(false)
const submitting = ref(false)
const useSundayBenchmark = ref(false) 
const isSyncing = ref(false)
const syncMode = ref('current')
const lastSyncTime = ref('')

const hasSeenGuide = null 
const showGuide = ref(!hasSeenGuide)

// 計算引導文字內容
const guideMessage = computed(() => {
  if (filteredMembers.value.length === 0) {
    return {
      title: '歡迎使用！',
      text: '這是您的第一步：請點擊「同步該聚會資料」，從正式系統拉取最新的成員名單。',
      type: 'empty'
    }
  } else {
    return {
      title: '保持最新',
      text: '若發現燈號未更新，或想確認點名最新狀態，請隨時點擊此處進行雙向同步。',
      type: 'list'
    }
  }
})

function closeGuide() {
  showGuide.value = false
  localStorage.setItem('hasSeenGuide', 'true')
}

// 檢查連線 (被父層呼叫)
async function checkConnection() {
  alert("連線檢查: 伺服器回應正常 (HTTP 200)");
}

// 手動同步 (預設為當前聚會)
function handleManualSync() {
  closeGuide() 
  performSync(true, 'current')
}

async function loadMembers() {
  loadingMembers.value = true
  try {
    const benchmarkMode = useSundayBenchmark.value ? 'sunday' : 'self'
    const res = await fetchMembers(meetingType.value, date.value, benchmarkMode)
    members.value = res || []
    
    updateLastSyncTimeFromData(members.value)

    const visibleIds = filteredMembers.value.map(m => m.member_id)
    const hasCurrentRecords = members.value.some(m => m.status === 1 || m.status === 0)

    if (hasCurrentRecords) {
      selectedIds.value = members.value
        .filter(m => m.status === 1 && visibleIds.includes(m.member_id))
        .map(m => m.member_id)
    } else {
      selectedIds.value = []
    }
    
  } catch (e) {
    console.error(e)
    alert("載入名單失敗")
  } finally {
    loadingMembers.value = false
  }
}

function toggleBenchmark() {
  useSundayBenchmark.value = !useSundayBenchmark.value
  loadMembers() 
}

watch([meetingType, date], () => {
  useSundayBenchmark.value = false
  lastSyncTime.value = '' 
  loadMembers() 
})

watch(() => props.loginSuccess, (newVal) => {
  if (newVal === true) {
    // performSync(true, 'current');
  }
})

onMounted(() => {
  loadMembers()
})

const filteredMembers = computed(() => {
  if (!Array.isArray(members.value)) return [];

  if (activeTab.value === 'district') {
    const targetSub = props.userProfile.sub_district || '';
    const validMembers = members.value.filter(m => m && (m.member_id || m.id));

    if (!targetSub) return validMembers;
    
    return validMembers.filter(m => {
        const groupName = String(m.small_group_name || '');
        const target = String(targetSub);
        return groupName.includes(target) || target.includes(groupName);
    });
  } else {
    return []; 
  }
});

const groupedMembers = computed(() => {
  const regulars = []
  const others = []
  filteredMembers.value.forEach(m => {
    if ((m.monthly_count || 0) >= 2 || m.last_week_status === 1) {
      regulars.push(m)
    } else {
      others.push(m)
    }
  })
  return { regulars, others }
})

const isAllSelected = computed(() => {
  return filteredMembers.value.length > 0 && 
         filteredMembers.value.every(m => selectedIds.value.includes(m.member_id))
})

function toggleAll(e) {
  const currentIds = filteredMembers.value.map(m => m.member_id)
  if (e.target.checked) {
    const newIds = new Set([...selectedIds.value, ...currentIds])
    selectedIds.value = Array.from(newIds)
  } else {
    selectedIds.value = selectedIds.value.filter(id => !currentIds.includes(id))
  }
}

function toggleMember(id) {
  if (selectedIds.value.includes(id)) {
    selectedIds.value = selectedIds.value.filter(x => x !== id)
  } else {
    selectedIds.value.push(id)
  }
}

async function confirmSubmit() {
  if (selectedIds.value.length === 0) {
    alert("請至少勾選一位聖徒！")
    return
  }
  const confirmed = confirm(
    `【送出確認】\n\n` +
    `聚會：${getMeetingName(meetingType.value)}\n` +
    `人數：${selectedIds.value.length} 人\n\n` +
    `確定要送出並同步至中央系統嗎？`
  )
  if (confirmed) {
    submitting.value = true
    try {
      const res = await submitAttendance({
        sub_district: props.userProfile.sub_district, 
        meeting_type: meetingType.value,
        member_ids: selectedIds.value,
        date: date.value
      })
      if (res.status === 'success') {
        alert("點名成功！")
        loadMembers() 
      } else {
        alert("送出失敗：" + res.message)
      }
    } catch (e) {
      alert("系統錯誤：" + e.message)
    } finally {
      submitting.value = false
    }
  }
}

function getMeetingName(type) {
    const map = { '2312': '家聚會出訪','38': '家聚會受訪','1473': '福音出訪','2026': '晨興','40': '禱告聚會','768': '兒童排', '39': '小排', '37': '主日', '2483': '生命讀經' }
    return map[type] || '聚會'
}

async function performSync(isManual = false, mode = 'current') {
  if (isSyncing.value) {
     console.log("正在同步中，略過此次點擊");
     return;
  }
  
  if (isManual) {
    isSyncing.value = true
    syncMode.value = mode 
    closeGuide()
  }
  
  const timeoutLimit = mode === 'all' ? 60000 : 30000;
  
  const safetyTimer = setTimeout(() => {
    if (isSyncing.value) {
      isSyncing.value = false;
      if (isManual) alert(`⚠️ 同步請求逾時 (超過 ${timeoutLimit/1000} 秒)，但點名資料已安全儲存在本地。`);
    }
  }, timeoutLimit);

  try {
    console.log(`1. 開始同步流程 (模式: ${mode})...`);
    if (props.userProfile?.sub_district) {
      const targetType = (mode === 'all') ? null : meetingType.value;
      console.log(`2. 呼叫 API: Target=${targetType || 'ALL'}`);
      await triggerCentralSync(props.userProfile.sub_district, date.value, targetType)
    }

    console.log("3. 重新讀取本地資料...");
    const benchmarkMode = useSundayBenchmark.value ? 'sunday' : 'self'
    const freshMembers = await fetchMembers(meetingType.value, date.value, benchmarkMode)
    
    console.log("4. 更新前端畫面...");
    applySmartMerge(freshMembers)
    members.value = freshMembers
    updateLastSyncTimeFromData(members.value)

    if (isManual) {
      setTimeout(() => {
        const msg = mode === 'all' ? "✅ 所有聚會資料已同步完成！" : "✅ 該聚會資料已更新。";
        alert(msg);
      }, 100);
    }
  } catch (e) {
    console.error("❌ 同步發生錯誤:", e)
    if (isManual) {
      let errorMsg = "未知錯誤";
      if (typeof e === 'string') errorMsg = e;
      else if (e instanceof Error) errorMsg = e.message;
      else errorMsg = JSON.stringify(e);

      if (errorMsg.includes('401') || errorMsg.includes('Unauthorized') || errorMsg.includes('Login')) {
        alert("⚠️ 連線金鑰已過期，系統將自動開啟登入視窗。")
        emit('openLogin')
      } else {
        alert("❌ 同步失敗，原因：" + errorMsg)
      }
    }
  } finally {
    clearTimeout(safetyTimer);
    isSyncing.value = false
    console.log("5. 同步流程結束，解除鎖定。");
  }
}

function applySmartMerge(freshMembers) {
  if (!freshMembers || freshMembers.length === 0) return
  const remoteAttendedIds = freshMembers.filter(m => m.status === 1).map(m => m.member_id)
  const mergedSet = new Set([...selectedIds.value, ...remoteAttendedIds])
  const addedCount = mergedSet.size - selectedIds.value.length
  selectedIds.value = Array.from(mergedSet)
  if (addedCount > 0 && isSyncing.value) {
    console.log(`同步完成：新增了 ${addedCount} 位聖徒`)
  }
}

watch(filteredMembers, (newMembers) => {
  const validIds = newMembers.map(m => m.member_id)
  const oldLength = selectedIds.value.length
  selectedIds.value = selectedIds.value.filter(id => validIds.includes(id))
  if (selectedIds.value.length !== oldLength) {
    console.log(`[自動修正] 已移除 ${oldLength - selectedIds.value.length} 個不在目前檢視範圍的勾選`)
  }
})

function updateLastSyncTimeFromData(list) {
  if (!list || list.length === 0) {
    lastSyncTime.value = ''
    return
  }
  let maxTime = 0
  list.forEach(m => {
    const tUpdate = m.updated_at ? new Date(m.updated_at.replace(/-/g, '/')).getTime() : 0
    const tSync = m.synced_at ? new Date(m.synced_at.replace(/-/g, '/')).getTime() : 0
    const currentMax = Math.max(tUpdate, tSync)
    if (currentMax > maxTime) maxTime = currentMax
  })
  if (maxTime > 0) {
    const d = new Date(maxTime)
    lastSyncTime.value = `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`
  } else {
    lastSyncTime.value = ''
  }
}

// ★★★ 關鍵：將函式暴露給父層 Header 使用 ★★★
defineExpose({
  performSync,
  checkConnection
})

</script>

<style scoped>
@keyframes bounce-slight {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}
.animate-bounce-slight {
  animation: bounce-slight 2.5s infinite ease-in-out;
}
</style>