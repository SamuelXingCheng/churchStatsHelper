<template>
  <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50 p-4">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-sm relative">
      <button class="absolute top-3 right-3 text-gray-400 hover:text-gray-600" @click="$emit('close')">
        ✕
      </button>

      <h3 class="text-lg font-bold mb-4 text-center text-gray-800">🔐 連線中央點名系統</h3>

      <div class="mb-6 text-center bg-gray-50 p-4 rounded-lg">
        <div v-if="loadingCaptcha" class="text-sm text-gray-500 py-2">
          取得驗證碼中...
        </div>
        <img 
          v-else-if="captchaUrl" 
          :src="captchaUrl" 
          alt="驗證碼" 
          class="mx-auto border rounded-lg shadow-sm mb-3 h-16 object-contain bg-white" 
        />
        <button class="text-xs text-blue-600 hover:text-blue-800 underline" @click="$emit('refreshCaptcha')">
          看不清楚？換一張
        </button>
      </div>

      <form @submit.prevent="$emit('submit', code)" class="space-y-4">
        <input
          v-model="code"
          type="text"
          placeholder="請輸入圖片中的數字"
          class="w-full border rounded-lg px-4 py-3 text-center text-lg tracking-widest outline-none focus:ring-2 focus:ring-blue-500"
          required
        />
        <button 
          type="submit"
          class="w-full bg-blue-600 text-white py-3 rounded-lg font-bold hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="loading"
        >
          {{ loading ? "登入中..." : "確認登入" }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
defineProps(["captchaUrl", "loading", "loadingCaptcha"]);
defineEmits(["close", "submit", "refreshCaptcha"]);
const code = ref("");
</script>