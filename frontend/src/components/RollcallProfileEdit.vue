<template>
  <div class="fixed inset-0 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm z-50 p-4 animate-fade-in">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden transform transition-all scale-100">
      
      <div class="bg-blue-700 p-4 flex justify-between items-center">
        <h2 class="text-white text-lg font-bold flex items-center">
          <span class="mr-2">👤</span> 個人設定
        </h2>
        <button v-if="isModal" @click="$emit('close')" class="text-white/80 hover:text-white transition">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div class="p-6">
        <p class="text-sm text-gray-500 mb-6 bg-blue-50 p-3 rounded-lg border border-blue-100">
          💡 請設定您所屬的區域，系統將會自動篩選名單給您。
        </p>

        <form @submit.prevent="handleSubmit" class="space-y-5">
          
          <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">大區 <span class="text-red-500">*</span></label>
            <input 
              v-model="form.main_district" 
              type="text" 
              placeholder="例如：建成"
              class="w-full bg-gray-50 border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">小區 <span class="text-red-500">*</span></label>
            <input 
              v-model="form.sub_district" 
              type="text" 
              placeholder="例如：三小組"
              class="w-full bg-gray-50 border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">Email (選填)</label>
            <input 
              v-model="form.email" 
              type="email" 
              class="w-full bg-gray-50 border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition"
            />
          </div>

          <button 
            type="submit" 
            class="w-full bg-blue-700 text-white py-3 rounded-xl hover:bg-blue-800 active:scale-95 transition font-bold shadow-lg"
            :disabled="loading"
          >
            {{ loading ? '儲存中...' : '儲存設定' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { syncUserProfile } from '../api/rollcall.js'

const props = defineProps({
  lineUserId: String,
  currentUser: Object // 從後端抓回來的預設值
})

const emit = defineEmits(['saved'])

const loading = ref(false)
const form = ref({
  main_district: '',
  sub_district: '',
  email: ''
})

// 初始化：填入現有資料
onMounted(() => {
  if (props.currentUser) {
    form.value.main_district = props.currentUser.main_district || ''
    form.value.sub_district = props.currentUser.sub_district || ''
    form.value.email = props.currentUser.email || ''
  }
})

async function handleSubmit() {
  loading.value = true
  try {
    const payload = {
      line_user_id: props.lineUserId,
      ...form.value
    }
    console.log("準備傳送的資料:", payload);
    const res = await syncUserProfile(payload)
    
    if (res.status === 'success') {
      alert('設定成功！')
      emit('saved', form.value) // 通知父層已完成
    } else {
      alert('儲存失敗：' + res.message)
    }
  } catch (e) {
    alert('系統錯誤：' + e.message)
  } finally {
    loading.value = false
  }
}
</script>