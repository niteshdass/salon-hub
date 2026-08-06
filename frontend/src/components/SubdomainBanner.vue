<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { APP_DOMAIN } from '@/lib/tenantHost'

const authStore = useAuthStore()

// Always the shareable {slug}.APP_DOMAIN string; prefer the resource's
// primary_domain — that is the row the server actually resolves a tenant
// for — and only derive it from the slug when the payload has none.
const domain = computed(() => {
  const org = authStore.organization
  if (!org) return null
  if (org.primary_domain) return org.primary_domain
  if (org.slug) return `${org.slug}.${APP_DOMAIN}`
  return null
})

// Real subdomains don't resolve on localhost, so in dev we Visit the
// path-based microsite instead. The displayed string stays the domain.
//
// In production the link is built from `domain` — the salon's own
// primary_domain row — rather than from a reconstructed `${slug}.apex`
// string, so what the banner sends an owner to is the exact host the
// server resolves a tenant for. The two cannot drift apart.
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
  try {
    await navigator.clipboard.writeText(domain.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    // Clipboard unavailable (insecure context / denied permission) — the
    // domain stays visible for manual copy; no confirmation to show.
  }
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
