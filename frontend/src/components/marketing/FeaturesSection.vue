<script setup>
// Each icon is expressed purely as <path> data so the card shell can loop.
const features = [
  {
    title: 'Your own booking site',
    body: 'A branded page your clients book from 24/7, no app required.',
    paths: [
      'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
      'M3 8.5h18',
      'M6 6.25h.01',
      'M8.5 6.25h.01',
      'm9 14 2 2 4-4',
    ],
  },
  {
    title: 'Automatic reminders',
    body: 'SMS and WhatsApp nudges that cut no-shows.',
    paths: ['M18 8.5a6 6 0 1 0-12 0c0 6-2.5 7.5-2.5 7.5h17S18 14.5 18 8.5Z', 'M10.5 20a2 2 0 0 0 3 0'],
  },
  {
    title: 'Deposits & payments',
    body: 'Take a deposit at booking so serious clients hold their slot.',
    paths: ['M3 6.5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M3 10h18', 'M6.5 14.5h4'],
  },
  {
    title: 'Reviews that build trust',
    body: 'Collect post-visit reviews automatically and show them off.',
    paths: ['M12 3.6l2.55 5.17 5.7.83-4.13 4.02.98 5.68L12 16.6l-5.08 2.7.98-5.68L3.75 9.6l5.7-.83z'],
  },
  {
    title: 'Reports built in',
    body: 'See bookings, revenue, and no-shows at a glance — no spreadsheet required.',
    paths: ['M4 20V10', 'M10 20V4', 'M16 20v-7', 'M3 20h18'],
  },
  {
    title: 'Staff & schedules',
    body: 'Per-stylist availability, time off, and services — handled.',
    paths: [
      'M16 20v-1.5a4 4 0 0 0-4-4H7.5a4 4 0 0 0-4 4V20',
      'M9.75 10.5a3.25 3.25 0 1 0 0-6.5 3.25 3.25 0 0 0 0 6.5Z',
      'M17 4.4a3.25 3.25 0 0 1 0 6.2',
      'M20.5 20v-1.5a4 4 0 0 0-3-3.87',
    ],
  },
]
</script>

<template>
  <section id="features" class="scroll-mt-24 py-20 sm:py-28">
    <div class="mx-auto max-w-6xl px-6 lg:px-8">
      <div class="max-w-2xl">
        <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
          <span class="h-px w-8 bg-brand-300"></span>
          Features
        </p>
        <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-ink sm:text-5xl">
          Everything your salon needs, in one place.
        </h2>
      </div>

      <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="f in features"
          :key="f.title"
          class="group rounded-2xl border border-brand-100 bg-white p-7 shadow-sm shadow-ink/[0.03] transition-all duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-xl hover:shadow-ink/[0.06]"
        >
          <span class="grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600 transition-colors duration-300 group-hover:bg-brand-500 group-hover:text-white">
            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path v-for="(d, i) in f.paths" :key="i" :d="d" />
            </svg>
          </span>
          <h3 class="mt-5 font-display text-xl font-semibold text-ink">{{ f.title }}</h3>
          <p class="mt-2 leading-relaxed text-ink/60">{{ f.body }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
