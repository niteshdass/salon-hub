<script setup>
import MarketingCta from './MarketingCta.vue'
import BookingDayMock from './mocks/BookingDayMock.vue'

// The seeded demo tenant (backend DemoSalonSeeder). If it is ever removed from
// production this must fall back to /salons — a dead link here is worse than a
// vaguer one.
const DEMO_SALON = '/salon/demo-salon'
</script>

<template>
  <section id="top" class="relative overflow-hidden">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-5 pt-14 pb-16 sm:pt-20 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:pt-28 lg:pb-24">
      <div class="max-w-xl">
        <p class="rise d0 inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white/70 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-brand-700 uppercase">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
          Booking software for salons
        </p>

        <h1 class="rise d1 mt-6 font-display text-[2.25rem] leading-[1.05] font-semibold tracking-tight text-ink sm:text-5xl lg:text-6xl">
          Your next client is trying to book at <span class="text-brand-600 italic">11pm.</span>
        </h1>

        <p class="rise d2 mt-6 text-lg leading-relaxed text-ink/70">
          Glowhub gives your salon its own booking page — it takes appointments while you're closed, sends
          the reminder, and holds the slot with an advance. No more scrolling back through Messenger to find
          who's coming at 4.
        </p>

        <div class="rise d3 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
          <MarketingCta to="/register" label="Register free" />
          <MarketingCta :to="DEMO_SALON" label="See a live booking page" variant="secondary" />
        </div>

        <p class="rise d3 mt-6 text-sm text-ink/50">Free forever · No card · Live in 10 minutes</p>
      </div>

      <div class="rise d4 relative mx-auto w-full max-w-md lg:mx-0">
        <BookingDayMock />

        <!--
          Floating proof card from the reference. lg-only and inset within
          this wrapper's own box (not hanging past the grid column) so it
          cannot reintroduce the 360px overflow a prior pass removed.
        -->
        <div
          aria-hidden="true"
          class="pointer-events-none absolute -top-4 right-3 hidden items-center gap-2.5 rounded-2xl border border-brand-100 bg-white px-3.5 py-2.5 shadow-xl shadow-ink/10 lg:flex"
        >
          <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 6 9 17l-5-5" />
            </svg>
          </span>
          <span class="text-xs leading-tight text-ink"><span class="block font-semibold">New booking</span><span class="block text-ink/55">৳500 advance</span></span>
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
