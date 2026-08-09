<script setup>
// The editorial rule-list: a term in the margin, a sentence beside it, a hair
// rule between. It carries both the pain band and the "also included" line, so
// `strong` exists to let one row read as the answer rather than another
// complaint.
defineProps({
  items: { type: Array, required: true },
})
</script>

<template>
  <dl class="border-t border-ink/10">
    <div
      v-for="item in items"
      :key="item.term + item.text"
      class="grid gap-1.5 border-b border-ink/10 py-5 sm:grid-cols-[8rem_1fr] sm:gap-8"
    >
      <dt
        :class="[
          'font-display text-sm tracking-wide uppercase',
          item.strong ? 'text-brand-600' : 'text-ink/65',
        ]"
      >
        {{ item.term }}
      </dt>
      <dd :class="['text-base leading-relaxed', item.strong ? 'text-ink' : 'text-ink/65']">
        {{ item.text }}
      </dd>
    </div>
  </dl>
</template>
