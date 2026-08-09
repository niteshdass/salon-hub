<script setup>
// Bars are fixed percentages, not data — this is a drawing of the reports
// page, and nothing here is presented as a measured result.
const week = [
  { day: 'Mon', height: 'h-8' },
  { day: 'Tue', height: 'h-14' },
  { day: 'Wed', height: 'h-10' },
  { day: 'Thu', height: 'h-20' },
  { day: 'Fri', height: 'h-24' },
  { day: 'Sat', height: 'h-16' },
]
</script>

<template>
  <div class="mx-auto w-full max-w-sm space-y-3">
    <div class="flex items-center gap-3 rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-ink/[0.06]">
      <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600">
        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      </span>
      <span class="min-w-0">
        <span class="block text-sm font-semibold text-ink">৳500 advance received</span>
        <span class="block text-xs text-ink/50">Balayage · Tuesday 11:30 · card</span>
      </span>
    </div>

    <div class="rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-ink/[0.06]">
      <div class="flex items-baseline justify-between">
        <p class="text-xs font-medium tracking-wide text-ink/45 uppercase">This week</p>
        <p class="font-display text-xl font-semibold text-ink">৳48,200</p>
      </div>
      <div class="mt-4 flex items-end justify-between gap-2" aria-hidden="true">
        <span v-for="b in week" :key="b.day" class="flex flex-1 flex-col items-center gap-1.5">
          <span :class="['w-full rounded-t-md bg-brand-300', b.height]"></span>
          <span class="text-[10px] text-ink/40">{{ b.day }}</span>
        </span>
      </div>
    </div>
  </div>
</template>
