<script setup>
// The hero, pricing card, closing band, nav and footer all shipped their own
// copy of this button and had already drifted on padding and shadow.
import { RouterLink } from 'vue-router'

defineProps({
  to: { type: String, required: true },
  label: { type: String, required: true },
  variant: { type: String, default: 'primary' },
  block: { type: Boolean, default: false },
  // The primary fill is near-black ink, which disappears on the closing
  // band's own bg-ink section. `invert` swaps primary to a paper fill with
  // an ink label so it stays a visible rectangle on a dark ground.
  invert: { type: Boolean, default: false },
})
</script>

<template>
  <RouterLink
    :to="to"
    :data-test="variant === 'primary' ? 'cta-primary' : undefined"
    :class="[
      'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-7 py-3.5 text-base font-semibold transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:outline-none',
      block ? 'w-full' : '',
      variant === 'primary'
        ? invert
          ? 'bg-paper text-ink hover:bg-white'
          : 'bg-ink text-white hover:bg-ink/90'
        : invert
          ? 'border border-paper/25 bg-transparent text-paper hover:bg-paper/10'
          : 'border border-ink/15 bg-white text-ink hover:border-ink/25 hover:bg-paper',
    ]"
  >
    {{ label }}
    <svg
      v-if="variant === 'primary'"
      viewBox="0 0 24 24"
      class="h-4 w-4"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M5 12h14M13 6l6 6-6 6" />
    </svg>
  </RouterLink>
</template>
