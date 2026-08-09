<script setup>
// The hero's proof: a day that filled itself. Pure CSS — no image assets,
// because the buyer is on a cheap Android over mobile data.
const appointments = [
  { time: '10:00', service: 'Cut & style', stylist: 'Dilruba', accent: 'bg-brand-400', status: 'Confirmed', chip: 'bg-brand-50 text-brand-700' },
  { time: '11:30', service: 'Balayage', stylist: 'Mira', accent: 'bg-rose-400', status: '৳500 advance', chip: 'bg-emerald-50 text-emerald-700' },
  { time: '14:00', service: 'Beard trim', stylist: 'Sam', accent: 'bg-brand-300', status: 'Confirmed', chip: 'bg-brand-50 text-brand-700' },
  { time: '16:30', service: 'Colour & gloss', stylist: 'Priya', accent: 'bg-brand-500', status: 'New', chip: 'bg-amber-50 text-amber-700' },
]
</script>

<template>
  <div class="rounded-3xl border border-brand-100 bg-white p-5 shadow-2xl shadow-ink/10 ring-1 ring-brand-50 sm:p-6">
    <div class="flex items-center justify-between gap-3">
      <div>
        <p class="text-xs font-medium tracking-wide text-ink/45 uppercase">Your booking page</p>
        <p class="mt-0.5 font-display text-lg font-semibold text-ink">Tuesday, 12 May</p>
      </div>
      <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700">
        <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
        6 booked
      </span>
    </div>

    <div class="mt-5 space-y-2.5">
      <div
        v-for="a in appointments"
        :key="a.time"
        class="flex items-center gap-3 rounded-2xl border border-brand-50 bg-paper/60 p-3"
      >
        <span class="w-11 shrink-0 font-display text-sm font-semibold text-ink/70">{{ a.time }}</span>
        <span :class="['h-9 w-1 shrink-0 rounded-full', a.accent]"></span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-ink">{{ a.service }}</p>
          <p class="truncate text-xs text-ink/50">with {{ a.stylist }}</p>
        </div>
        <span :class="['shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold', a.chip]">{{ a.status }}</span>
      </div>
    </div>

    <p class="mt-5 flex items-center gap-2 border-t border-brand-50 pt-4 text-xs text-ink/55">
      <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600">
        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      </span>
      Last one booked at 11:04pm, while you slept.
    </p>
  </div>
</template>
