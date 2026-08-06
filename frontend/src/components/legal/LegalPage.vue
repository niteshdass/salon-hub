<script setup>
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'

defineProps({
  title: { type: String, required: true },
  updated: { type: String, required: true },
})
</script>

<template>
  <div class="min-h-screen bg-paper text-ink">
    <MarketingNav />
    <main class="mx-auto max-w-3xl px-6 py-16">
      <h1 class="font-display text-4xl text-ink">{{ title }}</h1>
      <p class="mt-2 text-sm text-ink/60">Last updated {{ updated }}</p>
      <div class="legal-prose mt-10 space-y-6 text-ink/80">
        <slot />
      </div>
    </main>
    <MarketingFooter />
  </div>
</template>

<style scoped>
.legal-prose :deep(h2) {
  font-family: var(--font-display);
  font-size: 1.375rem;
  color: var(--color-ink);
  margin-top: 2rem;
}
.legal-prose :deep(p),
.legal-prose :deep(li) {
  line-height: 1.7;
}
.legal-prose :deep(ul) {
  list-style: disc;
  padding-left: 1.25rem;
}
</style>
