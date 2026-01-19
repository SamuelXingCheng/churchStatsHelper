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
      class="mb-5"
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
        
        <button 
          @click="handleManualSync" 
          :disabled="isSyncing"
          class="flex items-center space-x-1.5 px-3 py-1 rounded-full text-[10px] font-bold transition-all active:scale-95 border"
          :class="isSyncing 
            ? 'bg-gray-800 text-gray-400 border-gray-700 cursor-wait' 
            : 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30 hover:bg-indigo-500/20'"
        >
          <svg v-if="isSyncing" class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span>{{ isSyncing ? '同步中' : '同步' }}</span>
        </button>

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

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
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
const lastSyncTime = ref('')
let pollingTimer = null // 用來存計時器 ID

async function loadMembers() {
  loadingMembers.value = true
  try {
    const benchmarkMode = useSundayBenchmark.value ? 'sunday' : 'self'
    const res = await fetchMembers(meetingType.value, date.value, benchmarkMode)
    members.value = res || []
    
    // 計算目前小區看得到的人 (避免勾選到篩選外的人)
    const visibleIds = filteredMembers.value.map(m => m.member_id)

    // 檢查資料庫是否已有本週紀錄 (status 為 1 或 0 都算有紀錄)
    const hasCurrentRecords = members.value.some(m => m.status === 1 || m.status === 0)

    if (hasCurrentRecords) {
      // 情況 A：資料庫已有紀錄 -> 顯示資料庫中「已出席 (status=1)」的人
      selectedIds.value = members.value
        .filter(m => m.status === 1 && visibleIds.includes(m.member_id))
        .map(m => m.member_id)
    } else {
      // 情況 B：資料庫無紀錄 (新的一週) -> ★ 修改處：不進行預選，保持空白
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

// 當日期或聚會類型改變時，重置同步狀態並重新載入
watch([meetingType, date], () => {
  useSundayBenchmark.value = false
  lastSyncTime.value = '' // 清空上次更新時間
  loadMembers() // 這是切換聚會，所以應該是全量載入 (Overwrite)，不是 Merge
})

// 監聽登入狀態：一旦偵測到登入成功 (false -> true)，自動執行同步抓資料
watch(() => props.loginSuccess, (newVal) => {
  if (newVal === true) {
    console.log("偵測到登入成功，執行初次自動同步...");
    performSync(true); // 呼叫同步函式 (帶 true 顯示 loading 讓使用者知道正在跑)
  }
})

onMounted(() => {
  // 載入初始資料 (原本的邏輯)
  loadMembers()
})


// 1. 基礎篩選
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

// 2. 智慧分區邏輯
const groupedMembers = computed(() => {
  const regulars = []
  const others = []
  
  filteredMembers.value.forEach(m => {
    // 【修改點】：升級為常態名單的條件
    // 1. 活躍度夠高 (monthly_count >= 2)
    // 2. OR 上週有來 (last_week_status === 1) -> 這樣「新常客」就會出現在上面了
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
        // ★ 傳入小區名稱，這會解決您看到的「基底數字」問題
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

// 1. 執行同步 (包含 API 呼叫 + 智能合併)
async function performSync(isManual = false) {
  if (isSyncing.value) return
  
  // 如果是手動按的，顯示 Loading 轉圈圈；背景執行則不顯示
  if (isManual) isSyncing.value = true
  
  try {
    // Step A: 叫後端去爬中央網站 (Update Local DB from Central)
    if (props.userProfile?.sub_district) {
      await triggerCentralSync(props.userProfile.sub_district, date.value)
    }

    // Step B: 讀取最新的本地資料 (Get Fresh Data)
    const benchmarkMode = useSundayBenchmark.value ? 'sunday' : 'self'
    const freshMembers = await fetchMembers(meetingType.value, date.value, benchmarkMode)
    
    // Step C: 智能合併 (Smart Merge Logic)
    // 這裡不直接覆蓋 members.value，而是要比對 selectedIds
    applySmartMerge(freshMembers)

    // 更新顯示清單 (這會觸發畫面重繪)
    members.value = freshMembers
    
    // 更新時間顯示
    const now = new Date()
    lastSyncTime.value = `${now.getHours()}:${String(now.getMinutes()).padStart(2, '0')}`

  } catch (e) {
    console.error("同步失敗", e)
    if (isManual) alert("同步失敗，請檢查網路")
  } finally {
    isSyncing.value = false
  }
}

// 2. 智能合併演算法 (聯集邏輯)
function applySmartMerge(freshMembers) {
  if (!freshMembers || freshMembers.length === 0) return

  // 找出「最新資料中，已經是出席狀態 (status=1)」的人
  const remoteAttendedIds = freshMembers
    .filter(m => m.status === 1)
    .map(m => m.member_id)

  // 執行聯集 (Union)：目前勾選的 + 遠端已出席的
  // Set 會自動去除重複
  const mergedSet = new Set([...selectedIds.value, ...remoteAttendedIds])
  
  // 算出「因為這次同步而新增」的數量 (僅為了 UX 提示，可選)
  const addedCount = mergedSet.size - selectedIds.value.length
  
  // 更新勾選狀態
  selectedIds.value = Array.from(mergedSet)
  
  // UX 反饋 (僅手動同步時提示)
  if (addedCount > 0 && isSyncing.value) {
    console.log(`同步完成：新增了 ${addedCount} 位聖徒`)
  }
}

// 3. 手動同步入口
function handleManualSync() {
  performSync(true)
}

// ★ 新增這段：當「可見名單」改變時，自動清理「已勾選ID」
// 這能防止「我看不到這張卡片，但他卻被勾選了」的幽靈現象
watch(filteredMembers, (newMembers) => {
  // 取得目前畫面上所有人的 ID 清單
  const validIds = newMembers.map(m => m.member_id)
  
  // 只保留「還在畫面上」的 ID
  const oldLength = selectedIds.value.length
  selectedIds.value = selectedIds.value.filter(id => validIds.includes(id))
  
  if (selectedIds.value.length !== oldLength) {
    console.log(`[自動修正] 已移除 ${oldLength - selectedIds.value.length} 個不在目前檢視範圍的勾選`)
  }
})

</script>