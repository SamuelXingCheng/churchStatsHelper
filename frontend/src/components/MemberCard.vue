<template>
  <div 
    v-if="member"
    @click="$emit('toggle')"
    class="flex flex-col justify-center p-3 rounded-xl border-2 cursor-pointer transition-all duration-200 relative overflow-hidden min-h-[80px]"
    :class="isSelected 
      ? 'bg-navy-light border-accent-gold shadow-[0_0_15px_rgba(197,165,114,0.15)]' 
      : 'bg-navy-light/30 border-transparent hover:bg-navy-light/50 hover:border-white/10'"
  >
    <div v-if="isSelected" class="absolute inset-0 bg-accent-gold/10 z-0"></div>

    <div class="relative z-10 flex flex-col items-center text-center space-y-1">
      
      <div class="font-bold text-lg leading-tight break-words w-full" 
           :class="isSelected ? 'text-white drop-shadow-md' : 'text-gray-300'">
        {{ member.member_name || '無姓名' }}
      </div>

      <div class="text-xs font-medium" :class="isSelected ? 'text-blue-200' : 'text-gray-500'">
        {{ member.small_group_name || '未分組' }}
      </div>

      <div v-if="isNewRegular || member.last_week_status === 1" class="flex flex-wrap justify-center gap-1 mt-1">
         <span v-if="isNewRegular" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">
           新參加
         </span>
         <span v-if="member.last_week_status === 1" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-500/20 text-blue-300 border border-blue-500/30">
           上週有
         </span>
      </div>

    </div>

    <div v-if="member.status === 1" class="absolute top-1 right-1">
      <div class="w-2.5 h-2.5 rounded-full bg-green-500 shadow-lg shadow-green-500/50"></div>
    </div>

  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  member: Object,
  isSelected: Boolean
})

defineEmits(["toggle"])

const isNewRegular = computed(() => {
    return props.member.last_week_status === 1 && (props.member.monthly_count || 0) < 2
})
</script>