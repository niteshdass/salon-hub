<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

// Always the shareable {slug}.salonhub.com string; prefer the resource's
// primary_domain, fall back to deriving it from the slug.
const domain = computed(() => {
  const org = authStore.organization
  if (!org) return null
  if (org.primary_domain) return org.primary_domain
  if (org.slug) return `${org.slug}.salonhub.com`
  return null
})

// Real subdomains don't resolve on localhost, so in dev we Visit the
// path-based microsite instead. The displayed string stays the domain.
const visitUrl = computed(() => {
  const org = authStore.organization
  if (!org) return null
  if (import.meta.env.PROD && domain.value) return `https://${domain.value}`
  if (org.slug) return `/salon/${org.slug}`
  return null
})

const copied = ref(false)
async function copy() {
  if (!domain.value) return
  await navigator.clipboard.writeText(domain.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}
</script>

<template>
  <div
    v-if="domain"
    class="mb-6 flex flex-col gap-3 rounded-2xl border border-brand-200 bg-brand-50 p-5 sm:flex-row sm:items-center sm:justify-between"
  >
    <div>
      <p class="font-display text-lg text-ink">Your booking site is live</p>
      <p class="mt-1 font-mono text-sm text-brand-700">{{ domain }}</p>
    </div>
    <div class="flex gap-2">
      <button
        type="button"
        class="rounded-lg border border-brand-300 px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-100"
        @click="copy"
      >
        {{ copied ? 'Copied!' : 'Copy' }}
      </button>
      <a
        :href="visitUrl"
        target="_blank"
        rel="noopener"
        class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
      >
        Visit
      </a>
    </div>
  </div>
</template>
