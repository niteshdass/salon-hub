<script setup>
import { computed, ref } from 'vue'
import SalonProfileSettings from '@/components/settings/SalonProfileSettings.vue'
import ReminderSettings from '@/components/settings/ReminderSettings.vue'
import PaymentSettings from '@/components/settings/PaymentSettings.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

// Payments configure what money the salon collects and hold gateway secrets —
// owner-only, matching the API policy.
const tabs = computed(() => [
  { key: 'profile', label: 'Salon profile', blurb: 'Branding, contact details and the story on your public page.' },
  { key: 'reminders', label: 'Reminders', blurb: 'Appointment reminders and channel connection.' },
  ...(authStore.isOwner
    ? [{ key: 'payments', label: 'Payments', blurb: 'Booking deposits and how customers pay them.' }]
    : []),
])

const active = ref('profile')
</script>

<template>
  <div>
    <PageHeader title="Settings" subtitle="Your salon profile, reminders and payments." />

    <div class="mb-6 flex gap-1 border-b border-ink/10">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="border-b-2 px-4 py-2 text-sm font-medium transition"
        :class="active === tab.key ? 'border-accent-500 text-ink' : 'border-transparent text-ink/55 hover:text-ink'"
        @click="active = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Each panel loads its own data, so mount it only when opened. -->
    <SalonProfileSettings v-if="active === 'profile'" />
    <PaymentSettings v-else-if="active === 'payments'" />
    <ReminderSettings v-else />
  </div>
</template>
