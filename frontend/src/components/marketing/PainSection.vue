<script setup>
import RuleList from './RuleList.vue'

// Three losses the owner already recognises, then the same day with Glowhub.
// No feature is named until the next section — this band only has to make the
// reader feel seen.
const rows = [
  { term: 'Today', text: 'A client messages at midnight. You reply at 9am. She has booked somewhere else.' },
  { term: 'Today', text: 'Three appointments are confirmed, one shows up. Nobody was reminded.' },
  { term: 'Today', text: 'Someone asks the price of highlights. You type it out. Again.' },
  {
    term: 'Glowhub',
    text: 'She books herself, gets a reminder the day before, and the price was on the page.',
    strong: true,
  },
]
</script>

<template>
  <section class="bg-white py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <h2 class="font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-ink sm:text-4xl">
        The front desk you don't have.
      </h2>
      <div class="mt-8">
        <RuleList :items="rows" />
      </div>
    </div>
  </section>
</template>
