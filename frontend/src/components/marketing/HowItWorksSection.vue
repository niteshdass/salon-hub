<script setup>
const steps = [
  {
    n: '01',
    title: 'Register your salon',
    body: 'Create your account and claim your booking subdomain.',
  },
  {
    n: '02',
    title: 'Set up services & staff',
    body: 'Add what you offer, who does it, and when.',
  },
  {
    n: '03',
    title: 'Share your booking link',
    body: 'Drop it in your bio and start taking bookings.',
  },
]
</script>

<template>
  <section class="relative overflow-hidden py-20 sm:py-28">
    <!-- Warm band behind the steps -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/2 -z-10 h-72 -translate-y-1/2 bg-gradient-to-b from-brand-50/70 to-transparent"></div>

    <div class="mx-auto max-w-6xl px-6 lg:px-8">
      <div class="mx-auto max-w-2xl text-center">
        <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
          <span class="h-px w-8 bg-brand-300"></span>
          How it works
          <span class="h-px w-8 bg-brand-300"></span>
        </p>
        <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-ink sm:text-5xl">
          Live in three steps.
        </h2>
      </div>

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
