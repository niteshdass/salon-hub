<script setup>
import { RouterLink } from 'vue-router'

// Prices and includes are verbatim from the spec. The "Includes" string is
// split on the middle dot into checkmark bullets.
const tiers = [
  {
    name: 'Free',
    price: '$0',
    suffix: '',
    includes: '1 branch · online booking page · email notifications · up to 50 bookings/mo',
    featured: false,
  },
  {
    name: 'Starter',
    price: '$19',
    suffix: '/mo',
    includes: 'Everything in Free · SMS/WhatsApp reminders · payment deposits · unlimited bookings · customer reviews',
    featured: true,
  },
  {
    name: 'Business',
    price: '$49',
    suffix: '/mo',
    includes: 'Everything in Starter · multi-branch · staff management · custom domain · priority support',
    featured: false,
  },
]

const bullets = (s) => s.split('·').map((x) => x.trim())
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
          Simple pricing that grows with you.
        </h2>
      </div>

      <div class="mt-16 grid items-start gap-6 lg:grid-cols-3">
        <div
          v-for="tier in tiers"
          :key="tier.name"
          :class="[
            'relative flex flex-col rounded-3xl p-8 transition-all duration-300',
            tier.featured
              ? 'border-2 border-brand-300 bg-white shadow-2xl shadow-brand-500/15 lg:-translate-y-3'
              : 'border border-brand-100 bg-white/70 shadow-sm shadow-ink/[0.03] hover:border-brand-200 hover:shadow-lg',
          ]"
        >
          <!-- Most popular badge -->
          <span
            v-if="tier.featured"
            class="absolute -top-3.5 left-1/2 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-brand-500 px-4 py-1.5 text-xs font-semibold tracking-wide text-white uppercase shadow-lg shadow-brand-500/30"
          >
            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="currentColor">
              <path d="M12 2.5l2.9 5.88 6.49.94-4.7 4.58 1.11 6.46L12 17.8l-5.8 3.05 1.1-6.46-4.69-4.58 6.49-.94z" />
            </svg>
            Most popular
          </span>

          <h3 class="font-display text-2xl font-semibold text-ink">{{ tier.name }}</h3>

          <div class="mt-4 flex items-baseline gap-1">
            <span class="font-display text-5xl font-semibold tracking-tight text-ink">{{ tier.price }}</span>
            <span v-if="tier.suffix" class="text-lg font-medium text-ink/50">{{ tier.suffix }}</span>
          </div>

          <ul class="mt-8 space-y-3.5">
            <li v-for="item in bullets(tier.includes)" :key="item" class="flex items-start gap-3">
              <span
                :class="[
                  'mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full',
                  tier.featured ? 'bg-brand-500 text-white' : 'bg-brand-50 text-brand-600',
                ]"
              >
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 6 9 17l-5-5" />
                </svg>
              </span>
              <span class="text-sm leading-relaxed text-ink/70">{{ item }}</span>
            </li>
          </ul>

          <RouterLink
            to="/register"
            :class="[
              'mt-8 inline-flex w-full items-center justify-center rounded-full px-6 py-3 text-sm font-semibold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper',
              tier.featured
                ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/25 hover:-translate-y-0.5 hover:bg-brand-600'
                : 'border border-brand-200 text-brand-700 hover:border-brand-300 hover:bg-brand-50',
            ]"
          >
            Get started
          </RouterLink>
        </div>
      </div>
    </div>
  </section>
</template>
