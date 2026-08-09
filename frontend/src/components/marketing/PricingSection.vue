<script setup>
import SectionHeading from './SectionHeading.vue'
import MarketingCta from './MarketingCta.vue'

// v1 ships Free-only: no billing, no subscription state machine, no upgrade
// path exists yet. These limits are exactly what PlanLimit enforces
// (App\Services\PlanLimit::FREE_MAX_BRANCHES / FREE_MAX_STAFF) — this card
// must never promise more than the API actually holds.
const includes = [
  '1 branch',
  '10 staff',
  'Unlimited services',
  'Unlimited clients',
  'Your own booking page',
  'Calendar & appointments',
  'SMS & WhatsApp reminders',
  'Advance payments',
  'Reviews',
  'Reports, expenses & payroll',
]
</script>

<template>
  <section id="pricing" class="scroll-mt-24 bg-white py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <SectionHeading
        eyebrow="Pricing"
        title="৳0. Everything above."
        lede="Not a trial — the free plan is the product. Paid plans arrive when salons need more branches, and you will hear from us long before anything changes."
      />

      <ul class="mt-10 grid gap-x-8 gap-y-3.5 sm:grid-cols-2">
        <li v-for="item in includes" :key="item" class="flex items-start gap-3">
          <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-brand-500 text-white">
            <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 6 9 17l-5-5" />
            </svg>
          </span>
          <span class="text-base leading-relaxed text-ink/75">{{ item }}</span>
        </li>
      </ul>

      <div class="mt-10">
        <MarketingCta to="/register" label="Register free" />
      </div>

      <p class="mt-8 border-t border-ink/10 pt-6 text-sm leading-relaxed text-ink/65">
        Card and mobile-banking advances run through SSLCommerz. Connect your own Twilio account and SMS and
        WhatsApp reminders are billed by Twilio, not by us.
      </p>
    </div>
  </section>
</template>
