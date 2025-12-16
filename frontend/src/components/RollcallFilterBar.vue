<template>
  <div class="mb-4">
    <!-- 聚會項目 -->
    <div class="flex flex-wrap gap-2 mb-2">
      <button
        v-for="(name, id) in MEETING_NAMES"
        :key="id"
        class="px-3 py-1 rounded-lg border"
        :class="selectedMeeting === id ? 'bg-green-500 text-white' : 'bg-gray-100'"
        @click="$emit('update:meeting', id)"
      >
        {{ name }}
      </button>
    </div>

    <!-- 日期 -->
    <div class="flex gap-2">
      <button
        v-for="d in dates"
        :key="d.id"
        class="flex-1 px-3 py-1 rounded-lg border"
        :class="selectedDate === d.id ? 'bg-blue-500 text-white' : 'bg-gray-100'"
        @click="$emit('update:date', d.id)"
      >
        {{ d.name }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { MEETING_NAMES } from "../config/rollcallmeetings.js"

// 產生日期 (YYYY-MM-DD)
function getWeekDate(offset) {
  const today = new Date()
  today.setDate(today.getDate() + offset * 7) // 上週/下週
  return today.toISOString().slice(0, 10)
}

const dates = [
  { id: getWeekDate(-1), name: "上週" },
  { id: getWeekDate(0), name: "本週" },
  { id: getWeekDate(1), name: "下週" }
]

defineProps({
  selectedMeeting: String,
  selectedDate: String
})
defineEmits(["update:meeting", "update:date"])
</script>