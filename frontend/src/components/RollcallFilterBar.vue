<template>
  <div>
    <div class="flex flex-wrap gap-2 mb-4">
      <button
        v-for="(name, id) in MEETING_NAMES"
        :key="id"
        class="px-5 py-2 rounded-xl border text-sm font-bold tracking-wide transition-all"
        :class="meetingType === id 
          ? 'bg-accent-blue/20 text-blue-200 border-accent-blue/50 shadow-[0_0_10px_rgba(91,124,153,0.2)]' 
          : 'bg-navy-dark/50 text-gray-400 border-transparent hover:text-gray-200 hover:bg-navy-dark'"
        @click="$emit('update:meetingType', id)"
      >
        {{ name }}
      </button>
    </div>

    <div class="flex bg-navy-dark/50 p-1.5 rounded-xl border border-white/5">
      <button
        v-for="d in dates"
        :key="d.id"
        class="flex-1 py-2.5 rounded-lg text-sm transition-all relative"
        :class="date === d.id ? 'text-white font-bold' : 'text-gray-500 hover:text-gray-400'"
        @click="$emit('update:date', d.id)"
      >
        <div v-if="date === d.id" class="absolute inset-0 bg-navy-light rounded-lg shadow-sm border border-white/10 z-0"></div>
        <span class="relative z-10">{{ d.name }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { MEETING_NAMES } from "../config/rollcallmeetings.js"

function getWeekDate(offset) {
  const today = new Date()
  today.setDate(today.getDate() + offset * 7)
  return today.toISOString().slice(0, 10)
}

const dates = [
  { id: getWeekDate(-1), name: "上週" },
  { id: getWeekDate(0), name: "本週" },
  { id: getWeekDate(1), name: "下週" }
]

defineProps({
  meetingType: String,
  date: String
})
defineEmits(["update:meetingType", "update:date"])
</script>