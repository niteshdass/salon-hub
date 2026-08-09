<script setup>
import SectionHeading from './SectionHeading.vue'

const steps = [
  { n: '01', title: 'Register your salon', body: 'Two minutes. Name, phone, and your booking address is yours.' },
  { n: '02', title: 'Add services & staff', body: 'Five minutes, guided. What you offer, who does it, and when.' },
  { n: '03', title: 'Share your link', body: 'Bio, WhatsApp status, shop window. Bookings start arriving.' },
]
</script>

<template>
  <section class="relative overflow-hidden py-16 sm:py-24">
    <!-- Warm band behind the steps -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/2 -z-10 h-72 -translate-y-1/2 bg-gradient-to-b from-brand-50/70 to-transparent"></div>

    <div class="mx-auto max-w-6xl px-5 lg:px-8">
      <SectionHeading eyebrow="How it works" title="Live in three steps." align="center" />

      <div class="relative mt-16 grid gap-8 md:grid-cols-3">
        <!-- Connecting dashed line (desktop) -->
        <div aria-hidden="true" class="absolute top-9 right-[16%] left-[16%] hidden border-t-2 border-dashed border-brand-200 md:block"></div>

        <div v-for="step in steps" :key="step.n" class="relative flex flex-col items-center text-center">
          <span class="relative z-10 grid h-18 w-18 place-items-center rounded-2xl border border-brand-100 bg-white shadow-lg shadow-ink/5">
            <span class="font-display text-2xl font-semibold text-brand-500">{{ step.n }}</span>
          </span>
          <h3 class="mt-6 font-display text-xl font-semibold text-ink">{{ step.title }}</h3>
          <p class="mt-2 max-w-xs leading-relaxed text-ink/60">{{ step.body }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
