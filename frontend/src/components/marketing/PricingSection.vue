<script setup>
import { RouterLink } from 'vue-router'

// v1 ships Free-only: no billing, no subscription state machine, no upgrade
// path exists yet. The limits below are exactly what PlanLimit enforces
// (App\Services\PlanLimit::FREE_MAX_BRANCHES / FREE_MAX_STAFF) — this card
// must never promise more than the API actually holds.
const free = {
  name: 'Free',
  price: '$0',
  suffix: '',
  includes: [
    '1 branch',
    '10 staff',
    'Unlimited services',
    'Unlimited customers',
    'Your own booking website',
    'Calendar & appointments',
    'Reports',
    'SMS & WhatsApp reminders',
  ],
}
</script>

<template>
  <section id="pricing" class="scroll-mt-24 py-20 sm:py-28">
    <div class="mx-auto max-w-6xl px-6 lg:px-8">
      <div class="mx-auto max-w-2xl text-center">
        <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
          <span class="h-px w-8 bg-brand-300"></span>
          Pricing
          <span class="h-px w-8 bg-brand-300"></span>
        </p>
        <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-ink sm:text-5xl">
          Start free. Stay free.
        </h2>
      </div>

      <div class="mx-auto mt-16 grid max-w-3xl items-start gap-6 sm:grid-cols-2">
        <!-- Free: the one purchasable plan -->
        <div
          class="relative flex flex-col rounded-3xl border-2 border-brand-300 bg-white p-8 shadow-2xl shadow-brand-500/15"
        >
          <h3 class="font-display text-2xl font-semibold text-ink">{{ free.name }}</h3>

          <div class="mt-4 flex items-baseline gap-1">
            <span class="font-display text-5xl font-semibold tracking-tight text-ink">{{ free.price }}</span>
            <span v-if="free.suffix" class="text-lg font-medium text-ink/50">{{ free.suffix }}</span>
          </div>

          <ul class="mt-8 space-y-3.5">
            <li v-for="item in free.includes" :key="item" class="flex items-start gap-3">
              <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-brand-500 text-white">
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 6 9 17l-5-5" />
                </svg>
              </span>
              <span class="text-sm leading-relaxed text-ink/70">{{ item }}</span>
            </li>
          </ul>

          <RouterLink
            to="/register"
            class="mt-8 inline-flex w-full items-center justify-center rounded-full bg-brand-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-brand-500/25 transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper"
          >
            Get started
          </RouterLink>
        </div>

        <!-- More plans coming: no price, no CTA, no signup -->
        <div
          class="flex flex-col justify-center rounded-3xl border border-dashed border-brand-200 bg-paper/60 p-8 text-center sm:text-left"
        >
          <p class="text-xs font-semibold tracking-widest text-ink/40 uppercase">More plans coming</p>
          <p class="mt-4 leading-relaxed text-ink/60">
            More branches, more staff and custom domains are on the way. Start free; we'll tell you before
            anything changes.
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
