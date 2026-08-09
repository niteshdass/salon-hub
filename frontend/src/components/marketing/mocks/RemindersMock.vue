<script setup>
// Two channels, one message. Both are real: AppointmentReminderService sends
// over Twilio SMS and WhatsApp from the salon's own account.
const messages = [
  {
    channel: 'SMS',
    tint: 'bg-brand-50 text-brand-700',
    body: 'Rupali Beauty Lounge: your Balayage is tomorrow at 11:30 with Mira. Reply CANCEL to cancel.',
    meta: 'Sent 18:00, day before',
  },
  {
    channel: 'WhatsApp',
    tint: 'bg-emerald-50 text-emerald-700',
    body: 'Reminder: Cut & style, tomorrow 10:00 with Dilruba. See you soon!',
    meta: 'Delivered · Read',
  },
]
</script>

<template>
  <div class="mx-auto w-full max-w-sm space-y-3">
    <div
      v-for="m in messages"
      :key="m.channel"
      class="rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-ink/[0.06]"
    >
      <span :class="['inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold', m.tint]">{{ m.channel }}</span>
      <p class="mt-3 text-sm leading-relaxed text-ink/80">{{ m.body }}</p>
      <p class="mt-3 text-[11px] text-ink/45">{{ m.meta }}</p>
    </div>
  </div>
</template>
