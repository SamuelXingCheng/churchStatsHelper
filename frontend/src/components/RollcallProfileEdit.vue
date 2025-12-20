<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity" @click="$emit('close')"></div>

    <div class="bg-navy-light/95 backdrop-blur-md p-8 rounded-[32px] border border-white/10 shadow-2xl w-full max-w-md relative z-10 transform transition-all scale-100">
      
      <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-accent-gold/40 to-transparent"></div>

      <button 
        @click="$emit('close')" 
        class="absolute top-5 right-5 p-2 rounded-full text-gray-400 hover:text-white hover:bg-white/10 transition-colors"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>

      <h2 class="text-2xl font-black text-white mb-8 flex items-center justify-center">
        個人點名設定
      </h2>

      <form @submit.prevent="handleSubmit" class="space-y-8">
        <div class="space-y-3">
          <label class="text-sm font-bold text-blue-400 uppercase tracking-widest ml-1">所屬大區</label>
          <div class="relative">
            <select 
              v-model="form.main_district" 
              class="w-full bg-navy-base border-2 border-gray-700 text-white text-xl font-bold rounded-2xl px-5 py-5 appearance-none focus:outline-none focus:border-accent-gold transition-all"
            >
              <option value="" disabled>請選擇大區</option>
              <option v-for="district in DISTRICT_ORDER" :key="district" :value="district">
                {{ district }}
              </option>
            </select>
            <div class="absolute inset-y-0 right-5 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </div>
          </div>
        </div>

        <div class="space-y-3">
          <label class="text-sm font-bold text-blue-400 uppercase tracking-widest ml-1">所屬小區 (主要點名)</label>
          <div class="relative">
            <select 
              v-model="form.sub_district" 
              :disabled="!form.main_district"
              class="w-full bg-navy-base border-2 border-gray-700 text-white text-2xl font-black rounded-2xl px-5 py-5 appearance-none focus:outline-none focus:border-accent-gold transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <option value="" disabled>
                {{ form.main_district ? '請選擇小區' : '請先選擇大區' }}
              </option>
              <option v-for="sub in subDistrictOptions" :key="sub" :value="sub">
                {{ sub }}
              </option>
            </select>
            <div class="absolute inset-y-0 right-5 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </div>
          </div>
          
          <p v-if="needsSync" class="text-xs text-orange-400 ml-2 font-bold animate-pulse">
            ⚠️ 注意：修改此欄位將會重新載入名單
          </p>
        </div>

        <div class="space-y-3 pt-4 border-t border-white/10">
          <label class="text-sm font-bold text-accent-gold uppercase tracking-widest ml-1">跨區關注 (選填)</label>
          <input 
            v-model="form.monitored_districts" 
            type="text" 
            placeholder="例如：青職排, 兒童排"
            class="w-full bg-navy-base border-2 border-gray-700 text-white text-lg rounded-2xl px-5 py-4 focus:outline-none focus:border-accent-gold transition-all placeholder-gray-700"
          />
        </div>

        <div v-if="isSubmitting" class="rounded-2xl p-5 flex items-start space-x-4 animate-fade-in border-2"
             :class="needsSync ? 'bg-blue-600/20 border-blue-500/40' : 'bg-green-600/20 border-green-500/40'">
          
          <div class="mt-1">
            <svg class="animate-spin h-6 w-6" :class="needsSync ? 'text-blue-400' : 'text-green-400'" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </div>
          
          <div class="flex-1">
            <p class="text-base font-bold text-white">
              {{ needsSync ? '正在同步新名單...' : '正在儲存設定...' }}
            </p>
            <p class="text-sm opacity-80 mt-1" :class="needsSync ? 'text-blue-200' : 'text-green-200'">
              {{ needsSync ? '偵測到小區變更，系統正在搬運資料，請稍等 5-10 秒。' : '更新您的個人資料中...' }}
            </p>
          </div>
        </div>

        <div class="flex space-x-3 pt-2">
          <button 
            type="button" 
            @click="$emit('close')"
            class="flex-1 bg-gray-700 text-gray-300 font-bold text-lg py-4 rounded-[20px] hover:bg-gray-600 transition-all"
            v-if="!isSubmitting"
          >
            取消
          </button>
          
          <button 
            type="submit" 
            :disabled="isSubmitting"
            class="flex-[2] bg-gradient-to-r from-accent-gold to-[#b38e5d] text-navy-base font-black text-xl py-4 rounded-[20px] shadow-xl active:scale-[0.96] transition-all disabled:opacity-50 disabled:grayscale"
          >
            {{ isSubmitting ? '處理中...' : '確認儲存' }}
          </button>
        </div>

      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted, computed } from 'vue'
import { syncUserProfile } from '../api/rollcall.js'
// ★ 引入設定檔
import { DISTRICTS_DATA, DISTRICT_ORDER } from '../config/districts.js'

const props = defineProps({
  lineUserId: String,
  currentUser: { type: Object, default: () => ({}) },
  isModal: Boolean
})

const emit = defineEmits(['saved', 'close'])

const isSubmitting = ref(false)
const form = reactive({
  main_district: '',
  sub_district: '',
  monitored_districts: '',
  email: ''
})

// ★ 計算屬性：根據目前選的大區，自動篩選出對應的小區列表
const subDistrictOptions = computed(() => {
  const main = form.main_district
  return DISTRICTS_DATA[main] || []
})

// ★ 監聽：當大區改變時，自動清空小區 (避免資料不一致)
// 注意：只在使用者手動改變時才觸發清空，載入初始資料時不應觸發
watch(() => form.main_district, (newVal, oldVal) => {
  if (oldVal && newVal !== oldVal) {
    // 檢查新大區是否有原本的小區，如果沒有才清空
    const newOptions = DISTRICTS_DATA[newVal] || []
    if (!newOptions.includes(form.sub_district)) {
      form.sub_district = ''
    }
  }
})

// 載入初始資料
function loadData() {
  if (props.currentUser) {
    form.main_district = (props.currentUser.main_district || '').trim()
    form.sub_district = (props.currentUser.sub_district || '').trim()
    form.monitored_districts = (props.currentUser.monitored_districts || '').trim()
    form.email = (props.currentUser.email || '').trim()
  }
}

onMounted(loadData)
watch(() => props.currentUser, loadData, { deep: true })

// 偵測是否需要同步 (比較小區是否有變更)
const needsSync = computed(() => {
  const oldVal = (props.currentUser.sub_district || '').trim()
  const newVal = (form.sub_district || '').trim()
  return oldVal !== newVal && newVal !== ''
})

async function handleSubmit() {
  if (isSubmitting.value) return
  
  // 簡單驗證
  if (!form.main_district || !form.sub_district) {
    alert("請選擇大區與小區")
    return
  }

  isSubmitting.value = true
  
  try {
    const payload = {
      line_user_id: props.lineUserId,
      ...form
    }
    
    const res = await syncUserProfile(payload)
    
    if (res.status === 'success') {
      
      // ★★★ 修正點：偵測是否同步失敗 ★★★
      // 如果需要同步 (needsSync=true) 但後端回傳沒同步 (synced=false)，代表沒登入
      if (needsSync.value && res.synced === false) {
        alert(
          "✅ 設定已儲存！\n\n" +
          "⚠️ 注意：因尚未連線中央系統，無法自動下載小區名單。\n" +
          "請關閉此視窗後，點擊首頁上方的「立即連線」按鈕，名單才會出現喔！"
        )
      }

      setTimeout(() => {
        emit('saved', form)
      }, 500)

    } else {
      alert('儲存失敗：' + res.message)
      isSubmitting.value = false
    }
  } catch (e) {
    alert('系統錯誤：' + e.message)
    isSubmitting.value = false
  }
}
</script>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
  animation: fadeIn 0.3s ease-out forwards;
}
</style>