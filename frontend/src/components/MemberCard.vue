<template>
  <div 
    v-if="member"
    @click="$emit('toggle')"
    class="flex items-center justify-between p-4 rounded-xl border cursor-pointer transition-all duration-300 group relative overflow-hidden"
    :class="isSelected 
      ? 'bg-navy-light border-accent-gold/40 shadow-[0_0_15px_rgba(197,165,114,0.05)]' 
      : 'bg-navy-light/40 border-transparent hover:bg-navy-light hover:border-white/5'"
  >
    <div v-if="isSelected" class="absolute inset-0 bg-accent-gold/5 z-0"></div>

    <div class="flex items-center space-x-5 z-10">
      <div 
        class="w-6 h-6 rounded-full border flex items-center justify-center transition-all duration-300"
        :class="isSelected 
          ? 'bg-accent-gold border-accent-gold scale-100' 
          : 'border-gray-600 bg-transparent group-hover:border-gray-400'"
      >
        <svg v-if="isSelected" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-navy-base" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
        </svg>
      </div>

      <div>
        <div class="font-bold text-lg tracking-wide transition-colors" :class="isSelected ? 'text-white' : 'text-gray-200'">
          {{ member.member_name || '無姓名' }}
        </div>
        <div class="text-sm text-gray-500 mt-1 font-medium">
          {{ member.small_group_name || '未分組' }}
        </div>
      </div>
    </div>

    <div v-if="member.status === 1" class="z-10 px-2.5 py-1 rounded text-xs font-bold bg-green-900/30 text-green-500/80 border border-green-500/10">
      已點
    </div>
  </div>
</template>

<script setup>
// 4. 修正 Props 定義，統一接收 member 物件與 isSelected 狀態
const props = defineProps({
  member: {
    type: Object,
    required: true,
    default: () => ({ member_name: '', small_group_name: '', status: null })
  },
  isSelected: Boolean
})

defineEmits(["toggle"])
</script>