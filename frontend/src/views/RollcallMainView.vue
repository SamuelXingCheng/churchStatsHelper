!-- src/views/Rollcall/RollcallMainView.vue -->
<template>
  <div>
    <!-- 篩選列 (聚會/日期按鈕) -->
    <RollcallFilterBar
      :selectedMeeting="selectedMeeting"
      :selectedDate="selectedDate"
      @update:meeting="selectedMeeting = $event"
      @update:date="selectedDate = $event"
    />

    <!-- 名單卡片 -->
    <div class="grid gap-2"
         style="grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));">
      <MemberCard
        v-for="m in members"
        :key="m.member_id"
        :name="m.member_name"
        :status="m.status"
        :selected="selectedMembers.includes(m.member_id)"
        @toggle="toggleSelect(m.member_id)"
      />
    </div>

    <!-- 統計 & 送出 -->
    <div class="mt-4 text-center">
      <button
        class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600"
        @click="handleSubmit"
        :disabled="selectedMembers.length === 0"
      >
        送出點名
      </button>
    </div>

    <!-- 顯示回饋訊息 -->
    <div v-if="submitMessage" class="mt-4 text-center text-sm"
         :class="{
           'text-green-600': submitSuccess === true,
           'text-yellow-600': submitSuccess === 'pending',
           'text-red-600': submitSuccess === false
         }">
      {{ submitMessage }}
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from "vue"
import RollcallFilterBar from "../components/RollcallFilterBar.vue"
import MemberCard from "../components/MemberCard.vue"
import { fetchMembers, submitAttendance } from "../api/rollcall.js"
import { MEETINGS } from "../config/rollcallmeetings.js"

// 父層傳入中央連線狀態
const props = defineProps({
  loginSuccess: Boolean
})

const selectedMeeting = ref(MEETINGS.LORDSDAY)  // 預設主日
const selectedDate = ref(new Date().toISOString().slice(0, 10))

const members = ref([])
const selectedMembers = ref([])
const loadingMembers = ref(false)

const submitMessage = ref("")
// true = 本地+中央成功, "pending" = 本地成功但中央未同步, false = 失敗
const submitSuccess = ref(false)

// 抓名單
async function loadMembers() {
  loadingMembers.value = true
  try {
    members.value = await fetchMembers(selectedMeeting.value, selectedDate.value)
    console.log("名單來源：", members.value)
  } catch (err) {
    console.error("載入名單失敗：", err.message)
    members.value = []
  } finally {
    loadingMembers.value = false
  }
}

// 點選卡片
function toggleSelect(memberId) {
  const idx = selectedMembers.value.indexOf(memberId)
  if (idx >= 0) {
    selectedMembers.value.splice(idx, 1) // 取消
  } else {
    selectedMembers.value.push(memberId) // 出席
  }
}

// 送出點名
async function handleSubmit() {
  try {
    const result = await submitAttendance({
      district: "永和",
      meeting_type: selectedMeeting.value,
      member_ids: selectedMembers.value,
      attend: 1,
      date: selectedDate.value
    })

    console.log("送出結果：", result)

    if (result.status === "recorded" || result.status === "success") {
      if (props.loginSuccess && result.status === "success") {
        submitMessage.value = "✅ 點名成功，中央已同步"
        submitSuccess.value = true
      } else if (!props.loginSuccess) {
        submitMessage.value = "✅ 點名成功，但尚未同步中央，可協助或等待他人連線"
        submitSuccess.value = "pending"
      } else {
        submitMessage.value = "✅ 點名成功，但中央未成功同步"
        submitSuccess.value = "pending"
      }
    } else {
      submitMessage.value = "注意：" + (result.message || "未知錯誤")
      submitSuccess.value = false
    }
  } catch (err) {
    submitMessage.value = "❌ 系統錯誤：" + err.message
    submitSuccess.value = false
  }
}

// 當聚會或日期改變時，自動重新抓名單
watch([selectedMeeting, selectedDate], loadMembers)

onMounted(loadMembers)
</script>