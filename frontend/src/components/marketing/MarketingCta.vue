<script setup>
// The hero, pricing card, closing band, nav and footer all shipped their own
// copy of this button and had already drifted on padding and shadow.
import { RouterLink } from 'vue-router'

defineProps({
  to: { type: String, required: true },
  label: { type: String, required: true },
  variant: { type: String, default: 'primary' },
  block: { type: Boolean, default: false },
})
</script>

<template>
  <RouterLink
    :to="to"
    :class="[
      'inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-7 py-3.5 text-base font-semibold transition-all duration-200 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:outline-none',
      block ? 'w-full' : '',
      variant === 'primary'
        ? 'bg-brand-500 text-white shadow-xl shadow-brand-500/25 hover:-translate-y-0.5 hover:bg-brand-600 hover:shadow-brand-500/35'
        : 'border border-brand-200 bg-white/60 text-brand-700 hover:border-brand-300 hover:bg-white',
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
