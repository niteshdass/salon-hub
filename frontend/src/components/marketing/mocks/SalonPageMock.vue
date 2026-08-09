<script setup>
// The address an owner is actually handed is `{slug}.{APP_DOMAIN}` — the same
// string SubdomainBanner.vue shows them in the dashboard. Reading APP_DOMAIN
// here keeps the promise and the product from drifting apart per environment.
import { APP_DOMAIN } from '@/lib/tenantHost'

const services = [
  { name: 'Cut & style', meta: '45 min', price: '৳800' },
  { name: 'Balayage', meta: '2 hr', price: '৳4,500' },
  { name: 'Bridal makeup', meta: '90 min', price: '৳6,000' },
]
</script>

<template>
  <div class="mx-auto w-full max-w-xs rounded-[1.75rem] border border-brand-100 bg-white p-3 shadow-2xl shadow-ink/10">
    <p class="truncate rounded-full bg-paper px-3 py-1.5 text-center text-[11px] font-medium text-ink/45">
      your-salon.{{ APP_DOMAIN }}
    </p>
    <div class="mt-3 h-20 rounded-2xl bg-gradient-to-br from-brand-200 via-brand-100 to-rose-100"></div>
    <p class="mt-3 font-display text-lg font-semibold text-ink">Rupali Beauty Lounge</p>
    <p class="text-xs text-ink/50">Zindabazar, Sylhet · Open until 8pm</p>
    <ul class="mt-3 space-y-2">
      <li
        v-for="s in services"
        :key="s.name"
        class="flex items-center justify-between gap-2 rounded-xl border border-brand-50 bg-paper/60 px-3 py-2"
      >
        <span class="min-w-0">
          <span class="block truncate text-xs font-semibold text-ink">{{ s.name }}</span>
          <span class="block text-[10px] text-ink/45">{{ s.meta }}</span>
        </span>
        <span class="shrink-0 font-display text-sm font-semibold text-ink">{{ s.price }}</span>
      </li>
    </ul>
    <p class="mt-3 rounded-full bg-brand-500 py-2 text-center text-xs font-semibold text-white">Book now</p>
  </div>
</template>
