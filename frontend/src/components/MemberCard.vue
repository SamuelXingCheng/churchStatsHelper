<template>
  <button
    @click="$emit('toggle')"
    class="w-full h-12 flex items-center justify-center 
           rounded-lg text-sm font-medium transition-colors"
    :class="buttonClass"
  >
    {{ name }}
  </button>
</template>

<script setup>
import { computed } from "vue"

const props = defineProps({
  name: String,
  selected: Boolean,   // 前端點選的狀態
  status: [Number, null] // 後端資料庫狀態 (1=已出席, null=未出席)
})
defineEmits(["toggle"])

const buttonClass = computed(() => {
  if (props.selected || props.status === 1) {
    return "bg-green-500 text-white"
  }
  return "bg-gray-200 text-gray-800"
})
</script>