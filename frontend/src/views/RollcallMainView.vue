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
      <div class="text-[10px] text-blue-300 bg-[#0f172a] px-3 py-1 rounded-full border border-blue-500/20">
        已選 <span class="font-bold text-white text-xs ml-0.5">{{ selectedIds.length }}</span> 人
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
              {{ useSundayBenchmark ? '📋 主日常客' : '📋 本會常客' }}
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
            <span class="text-sm font-bold">🌱 關懷名單</span>
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
import { ref, computed, watch, onMounted } from 'vue'
import RollcallFilterBar from '../components/RollcallFilterBar.vue'
import MemberCard from '../components/MemberCard.vue' 
import { fetchMembers, submitAttendance } from '../api/rollcall.js'

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

async function loadMembers() {
  loadingMembers.value = true
  try {
    const benchmarkMode = useSundayBenchmark.value ? 'sunday' : 'self'
    const res = await fetchMembers(meetingType.value, date.value, benchmarkMode)
    members.value = res || []
    
    // 智慧預選：若本週尚未有點名紀錄 (status 為 null 或 0)，則自動勾選「上週有來」的人
    // 若 status === 1，代表資料庫已有紀錄，就只顯示已紀錄的
    const hasCurrentRecords = members.value.some(m => m.status === 1 || m.status === 0)

    if (hasCurrentRecords) {
      selectedIds.value = members.value
        .filter(m => m.status === 1)
        .map(m => m.member_id)
    } else {
      selectedIds.value = members.value
        .filter(m => m.last_week_status === 1)
        .map(m => m.member_id)
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
  loadMembers()
})

onMounted(loadMembers)

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
        district: props.userProfile.main_district,
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
    const map = { '38': '家聚會', '39': '小排', '37': '主日' }
    return map[type] || '聚會'
}
</script>