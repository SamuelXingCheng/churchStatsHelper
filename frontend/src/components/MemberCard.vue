<template>
  <button
    @click="$emit('toggle')"
    class="w-full h-12 flex items-center justify-center rounded-lg text-sm font-medium transition-all duration-200 shadow-sm border"
    :class="buttonClass"
  >
    {{ name }}
    <span v-if="status === 1 && !selected" class="ml-1 text-xs opacity-60">(已點)</span>
  </button>
</template>

<script setup>
import { computed } from "vue";
const props = defineProps({
  name: String,
  selected: Boolean,
  status: [Number, null]
});
defineEmits(["toggle"]);

const buttonClass = computed(() => {
  if (props.selected) return "bg-green-500 text-white border-green-600 transform scale-105"; // 當前選中
  if (props.status === 1) return "bg-green-100 text-green-800 border-green-200"; // 資料庫已記錄
  return "bg-white text-gray-700 border-gray-200 hover:bg-gray-50"; // 未選
});
</script>