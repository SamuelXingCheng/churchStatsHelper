<template>
  <button
    @click="$emit('toggle')"
    class="w-full h-12 flex items-center justify-center rounded-lg text-sm font-medium transition-all duration-200 border select-none active:scale-95"
    :class="buttonClass"
  >
    {{ name }}
    <span v-if="status === 1 && !selected" class="ml-1 text-xs opacity-60">
      (已點)
    </span>
  </button>
</template>

<script setup>
import { computed } from "vue"

const props = defineProps({
  name: String,
  selected: Boolean,   // 前端目前選取的狀態
  status: [Number, null] // 資料庫讀出的狀態 (1=已出席)
})
defineEmits(["toggle"])

const buttonClass = computed(() => {
  if (props.selected) {
    // 被選取 (綠底白字)
    return "bg-green-500 text-white border-green-600 shadow-md transform scale-[1.02]"
  }
  if (props.status === 1) {
    // 資料庫已有點名紀錄 (淺綠底)
    return "bg-green-50 text-green-700 border-green-200"
  }
  // 未選取 (灰白底)
  return "bg-white text-gray-700 border-gray-200 hover:bg-gray-50 hover:border-gray-300"
})
</script>