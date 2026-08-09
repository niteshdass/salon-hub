<script setup>
import MarketingCta from './MarketingCta.vue'

// This replaces three testimonials we wrote ourselves. Nothing is live yet, so
// there is no honest version of social proof — the next best thing is saying
// so, and answering the questions a first customer would actually ask.
const promises = [
  {
    title: 'No fake reviews here.',
    body: "We won't show you testimonials we don't have. When real salons have something to say, you'll see their names.",
  },
  {
    title: 'Built in Bangladesh, for salons here.',
    body: 'Taka, local card and mobile banking, and the way your clients actually message you.',
  },
  {
    title: 'Your client list is yours.',
    body: 'Export it any time, in full. Leave any time. Nothing is held hostage.',
  },
  {
    title: 'You can reach a human.',
    body: 'Reply to any email from us and a founder answers. There is no ticket queue.',
  },
]
</script>

<template>
  <section class="bg-white py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <h2 class="font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-ink sm:text-4xl">
        We're new. Here's what that means.
      </h2>

      <dl class="mt-10 space-y-7">
        <div v-for="p in promises" :key="p.title">
          <dt class="font-display text-lg font-semibold text-ink">{{ p.title }}</dt>
          <dd class="mt-1.5 leading-relaxed text-ink/65">{{ p.body }}</dd>
        </div>
      </dl>

      <div class="mt-10">
        <MarketingCta to="/salon/demo-salon" label="See a real booking page" variant="secondary" />
      </div>
    </div>
  </section>
</template>
