<template>
  <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-80 relative">
      <button class="absolute top-2 right-2 text-gray-500 hover:text-gray-700"
              @click="$emit('close')">
        ✖
      </button>

      <h3 class="text-lg font-bold mb-4 text-center">連線中央點名系統</h3>

      <div class="mb-4 text-center">
        <div v-if="captchaLoading" class="text-sm text-gray-600">
          正在取得中央系統驗證碼，請稍候...
        </div>

        <img v-else-if="captchaUrl"
             :src="captchaUrl"
             alt="驗證碼"
             class="mx-auto border rounded mb-2" />

        <button class="text-sm text-blue-600 underline mt-2"
                @click="$emit('loadCaptcha')"
                :disabled="captchaLoading">
          {{ captchaLoading ? "重新取得中..." : "重新取得驗證碼" }}
        </button>
      </div>

      <form @submit.prevent="$emit('submitLogin')" class="space-y-3">
        <input
          :value="verifyCode"
          @input="$emit('update:verifyCode', $event.target.value)"
          type="text"
          placeholder="輸入驗證碼"
          class="w-full border rounded px-3 py-2"
          :disabled="loading || captchaLoading"
        />
        <button type="submit"
                class="w-full bg-green-500 text-white py-2 px-4 rounded-lg hover:bg-green-600 disabled:bg-gray-300"
                :disabled="loading || captchaLoading">
          {{ loading ? "登入中..." : "登入" }}
        </button>
      </form>

      <div v-if="loading" class="mt-3 text-center text-sm text-gray-600">
        正在連線中央點名系統，請稍候...
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  captchaUrl: String,
  verifyCode: String,
  loading: Boolean,
  captchaLoading: Boolean
})
defineEmits(["update:verifyCode", "submitLogin", "loadCaptcha", "close"])
</script>