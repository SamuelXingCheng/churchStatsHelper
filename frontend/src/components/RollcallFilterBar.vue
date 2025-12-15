<template>
  <div class="flex flex-col gap-3 bg-gray-50 p-3 rounded-lg border border-gray-100 shadow-sm">
    <div class="flex items-center gap-2">
      <label class="text-sm font-bold text-gray-600 w-12 text-right">日期</label>
      <input
        type="date"
        class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white"
        :value="selectedDate"
        @input="$emit('update:date', $event.target.value)"
      />
    </div>

    <div class="flex items-center gap-2">
      <label class="text-sm font-bold text-gray-600 w-12 text-right">聚會</label>
      <select
        class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white"
        :value="selectedMeeting"
        @change="$emit('update:meeting', $event.target.value)"
      >
        <option v-for="(name, code) in MEETING_NAMES" :key="code" :value="code">
          {{ name }}
        </option>
      </select>
    </div>
  </div>
</template>

<script setup>
import { MEETING_NAMES } from "../config/rollcallmeetings.js"; // 注意路徑
defineProps(["selectedMeeting", "selectedDate"]);
defineEmits(["update:meeting", "update:date"]);
</script>