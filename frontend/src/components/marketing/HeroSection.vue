<script setup>
import { RouterLink } from 'vue-router'

// Illustrative schedule rows for the CSS product mock (no external assets).
const appointments = [
  { time: '10:00', service: 'Cut & Style', stylist: 'Devan', accent: 'bg-brand-400', status: 'Confirmed', chip: 'bg-brand-50 text-brand-700' },
  { time: '11:30', service: 'Balayage', stylist: 'Mara', accent: 'bg-rose-400', status: 'Deposit paid', chip: 'bg-emerald-50 text-emerald-700' },
  { time: '14:00', service: 'Beard trim', stylist: 'Sam', accent: 'bg-brand-300', status: 'Confirmed', chip: 'bg-brand-50 text-brand-700' },
  { time: '16:30', service: 'Colour & gloss', stylist: 'Priya', accent: 'bg-brand-500', status: 'New', chip: 'bg-amber-50 text-amber-700' },
]
</script>

<template>
  <section id="top" class="relative overflow-hidden">
    <!-- Warm atmospheric glows -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
      <div class="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-brand-200/40 blur-3xl"></div>
      <div class="absolute top-40 -left-32 h-96 w-96 rounded-full bg-rose-200/30 blur-3xl"></div>
    </div>

    <div class="mx-auto grid max-w-6xl items-center gap-16 px-6 pt-16 pb-20 sm:pt-24 lg:grid-cols-[1.05fr_0.95fr] lg:gap-12 lg:px-8 lg:pt-28 lg:pb-28">
      <!-- Copy -->
      <div class="max-w-xl">
        <p class="rise d0 inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white/70 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-brand-700 uppercase">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
          Booking software for salons
        </p>

        <h1 class="rise d1 mt-6 font-display text-5xl leading-[1.03] font-semibold tracking-tight text-ink sm:text-6xl">
          Run your salon.
          <span class="block text-brand-600">We'll run the booking.</span>
        </h1>

        <p class="rise d2 mt-6 text-lg leading-relaxed text-ink/70">
          SalonHub gives your salon its own booking site, automatic reminders, deposits, and reviews — so your chair stays full and your front desk stays calm.
        </p>

        <div class="rise d3 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
          <RouterLink
            to="/register"
            class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-500 px-7 py-3.5 text-base font-semibold text-white shadow-xl shadow-brand-500/25 transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-600 hover:shadow-brand-500/35 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper"
          >
            Register a salon
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M5 12h14M13 6l6 6-6 6" />
            </svg>
          </RouterLink>
          <RouterLink
            to="/login"
            class="inline-flex items-center justify-center rounded-full border border-brand-200 bg-white/60 px-7 py-3.5 text-base font-semibold text-brand-700 transition-all duration-200 hover:border-brand-300 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
          >
            Log in
          </RouterLink>
        </div>

        <p class="rise d3 mt-6 text-sm text-ink/50">
          Free to start · No credit card · Your booking page live in minutes
        </p>
      </div>

      <!-- CSS product mock -->
      <div class="rise d4 relative mx-auto w-full max-w-md lg:mx-0">
        <!-- Calendar card -->
        <div class="relative rounded-3xl border border-brand-100 bg-white p-6 shadow-2xl shadow-ink/10 ring-1 ring-brand-50">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-xs font-medium tracking-wide text-ink/45 uppercase">Your booking page</p>
              <p class="mt-0.5 font-display text-lg font-semibold text-ink">Tuesday, 12 May</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700">
              <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
              6 booked
            </span>
          </div>

          <div class="mt-5 space-y-2.5">
            <div
              v-for="a in appointments"
              :key="a.time"
              class="flex items-center gap-3 rounded-2xl border border-brand-50 bg-paper/60 p-3 transition-colors hover:bg-paper"
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
        </div>

        <!-- Floating: new booking toast -->
        <div class="absolute -top-5 -right-3 flex items-center gap-2.5 rounded-2xl border border-brand-50 bg-white px-4 py-3 shadow-xl shadow-ink/10 sm:-right-6">
          <span class="grid h-8 w-8 place-items-center rounded-full bg-emerald-50 text-emerald-600">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 6 9 17l-5-5" />
            </svg>
          </span>
          <div class="leading-tight">
            <p class="text-xs font-semibold text-ink">New booking</p>
            <p class="text-[11px] text-ink/50">Deposit paid · $15</p>
          </div>
        </div>

        <!-- Floating: stat chip -->
        <div class="absolute -bottom-6 -left-3 flex items-center gap-3 rounded-2xl border border-brand-50 bg-white px-4 py-3 shadow-xl shadow-ink/10 sm:-left-6">
          <div class="flex items-end gap-0.5" aria-hidden="true">
            <span class="w-1.5 rounded-full bg-brand-200" style="height: 10px"></span>
            <span class="w-1.5 rounded-full bg-brand-300" style="height: 16px"></span>
            <span class="w-1.5 rounded-full bg-brand-400" style="height: 22px"></span>
            <span class="w-1.5 rounded-full bg-brand-500" style="height: 28px"></span>
          </div>
          <div class="leading-tight">
            <p class="font-display text-base font-semibold text-ink">No-shows ↓ 43%</p>
            <p class="text-[11px] text-ink/50">since deposits turned on</p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(18px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.rise {
  animation: rise 0.7s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}

.d0 {
  animation-delay: 0.02s;
}
.d1 {
  animation-delay: 0.1s;
}
.d2 {
  animation-delay: 0.2s;
}
.d3 {
  animation-delay: 0.3s;
}
.d4 {
  animation-delay: 0.42s;
}

@media (prefers-reduced-motion: reduce) {
  .rise {
    animation: none;
  }
}
</style>
