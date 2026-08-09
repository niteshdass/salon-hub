<script setup>
// Every section used to hand-roll this eyebrow-rule + serif h2 pair, and the
// heading size had already drifted between them. Centred headings close the
// rule on both sides; left-aligned ones open it only on the left.
defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  lede: { type: String, default: '' },
  align: { type: String, default: 'left' },
})
</script>

<template>
  <div :class="['max-w-2xl', align === 'center' ? 'mx-auto text-center' : '']">
    <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
      <span data-rule class="h-px w-8 bg-brand-300"></span>
      {{ eyebrow }}
      <span v-if="align === 'center'" data-rule class="h-px w-8 bg-brand-300"></span>
    </p>
    <h2 class="mt-4 font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-ink sm:text-4xl lg:text-5xl">
      {{ title }}
    </h2>
    <p v-if="lede" class="lede mt-5 text-lg leading-relaxed text-ink/65">{{ lede }}</p>
  </div>
</template>
