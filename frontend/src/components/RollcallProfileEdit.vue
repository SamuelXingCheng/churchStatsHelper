<template>
  <div class="fixed inset-0 flex items-center justify-center bg-gray-100 z-50 p-4">
    <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-sm">
      <h2 class="text-xl font-bold mb-2 text-center">完善個人資料</h2>
      <p class="text-sm text-gray-500 mb-6 text-center">
        為了方便點名，請先設定您所屬的大區與小區。
      </p>

      <form @submit.prevent="handleSubmit" class="space-y-4">
        
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">所屬大區 <span class="text-red-500">*</span></label>
          <input 
            v-model="form.main_district" 
            type="text" 
            placeholder="例如：建成"
            class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">所屬小區 <span class="text-red-500">*</span></label>
          <input 
            v-model="form.sub_district" 
            type="text" 
            placeholder="例如：三小組"
            class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email (選填)</label>
          <input 
            v-model="form.email" 
            type="email" 
            placeholder="您的電子信箱"
            class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none"
          />
        </div>

        <button 
          type="submit" 
          class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition font-medium"
          :disabled="loading"
        >
          {{ loading ? '儲存中...' : '儲存設定' }}
        </button>
      </form>
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