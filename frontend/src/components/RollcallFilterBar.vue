<template>
  <div class="space-y-3 mb-6 bg-gray-50 p-3 rounded-lg shadow-sm">
    <div class="flex items-center space-x-2">
      <label class="text-gray-600 font-medium w-16">日期</label>
      <input 
        type="date" 
        :value="selectedDate"
        @input="$emit('update:date', $event.target.value)"
        class="flex-1 border rounded px-3 py-2 bg-white outline-none focus:ring-2 focus:ring-blue-300"
      >
    </div>
    <div class="flex items-center space-x-2">
      <label class="text-gray-600 font-medium w-16">聚會</label>
      <select 
        :value="selectedMeeting"
        @change="$emit('update:meeting', $event.target.value)"
        class="flex-1 border rounded px-3 py-2 bg-white outline-none focus:ring-2 focus:ring-blue-300"
      >
        <option v-for="(name, code) in MEETING_NAMES" :key="code" :value="code">
          {{ name }}
        </option>
      </select>
    </div>
  </div>
</template>

<script setup>
import { MEETING_NAMES } from "../config/rollcallmeetings.js";
defineProps(["selectedMeeting", "selectedDate"]);
defineEmits(["update:meeting", "update:date"]);
</script>