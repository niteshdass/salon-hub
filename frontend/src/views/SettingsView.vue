<script setup>
import { ref } from 'vue'
import SalonProfileSettings from '@/components/settings/SalonProfileSettings.vue'
import ReminderSettings from '@/components/settings/ReminderSettings.vue'

const tabs = [
  { key: 'profile', label: 'Salon profile', blurb: 'Branding, contact details and the story on your public page.' },
  { key: 'reminders', label: 'Reminders', blurb: 'Appointment reminders and channel connection.' },
]

const active = ref('profile')
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-slate-900">Settings</h1>
      <p class="mt-1 text-sm text-slate-500">
        {{ tabs.find((t) => t.key === active).blurb }}
      </p>
    </div>

    <div class="mb-6 flex gap-1 border-b border-slate-200">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition"
        :class="
          active === tab.key
            ? 'border-indigo-600 text-indigo-700'
            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
        "
        @click="active = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Each panel loads its own data, so mount it only when opened. -->
    <SalonProfileSettings v-if="active === 'profile'" />
    <ReminderSettings v-else />
  </div>
</template>
