<template>
  <div 
    @click="$emit('toggle')"
    class="flex items-center justify-between p-3.5 rounded-xl border cursor-pointer transition-all duration-200 group relative overflow-hidden"
    :class="isSelected 
      ? 'bg-[#1e40af]/40 border-blue-500/50 shadow-[0_0_15px_rgba(59,130,246,0.1)]' 
      : 'bg-[#1e325c] border-transparent hover:bg-[#253d70] hover:border-white/10'"
  >
    <div v-if="isSelected" class="absolute inset-0 bg-blue-600/10 z-0"></div>

    <div class="flex items-center space-x-4 z-10">
      <div 
        class="w-5 h-5 rounded-md border flex items-center justify-center transition-all duration-300"
        :class="isSelected 
          ? 'bg-blue-500 border-blue-500 scale-110' 
          : 'border-gray-500 bg-transparent group-hover:border-gray-400'"
      >
        <svg v-if="isSelected" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-white" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
        </svg>
      </div>

      <div>
        <div class="font-bold text-[15px] tracking-wide" :class="isSelected ? 'text-white' : 'text-gray-200'">
          {{ member.member_name }}
        </div>
        <div class="text-[11px] text-gray-400 mt-0.5 font-medium">
          {{ member.small_group_name || '未分組' }}
        </div>
      </div>
    </div>

    <div v-if="member.status === 1" class="z-10 px-2 py-0.5 rounded text-[10px] font-bold bg-green-500/20 text-green-400 border border-green-500/30">
      已點
    </div>
  </div>
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