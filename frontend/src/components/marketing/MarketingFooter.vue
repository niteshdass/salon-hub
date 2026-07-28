<script setup>
import { RouterLink } from 'vue-router'

const productLinks = [
  { label: 'Features', href: '#features' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'FAQ', href: '#faq' },
  { label: 'Contact', href: '#contact' },
]
</script>

<template>
  <footer class="bg-ink text-paper/70">
    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8">
      <div class="grid gap-10 md:grid-cols-[1.4fr_1fr_1fr]">
        <!-- Brand -->
        <div>
          <div class="flex items-center gap-2.5">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white">
              <span class="font-display text-lg font-semibold leading-none">S</span>
            </span>
            <span class="font-display text-xl font-semibold text-paper">SalonHub</span>
          </div>
          <p class="mt-4 max-w-xs leading-relaxed text-paper/55">Booking software for modern salons.</p>
          <a
            href="mailto:hello@salonhub.com"
            class="mt-5 inline-block text-sm text-paper/70 underline decoration-paper/20 underline-offset-4 transition-colors hover:text-paper hover:decoration-paper/50"
          >
            hello@salonhub.com
          </a>
        </div>

        <!-- Product -->
        <div>
          <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Product</p>
          <ul class="mt-4 space-y-3">
            <li v-for="link in productLinks" :key="link.href">
              <a :href="link.href" class="text-paper/70 transition-colors hover:text-paper">{{ link.label }}</a>
            </li>
          </ul>
        </div>

        <!-- Account -->
        <div>
          <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Account</p>
          <ul class="mt-4 space-y-3">
            <li>
              <RouterLink to="/login" class="text-paper/70 transition-colors hover:text-paper">Log in</RouterLink>
            </li>
            <li>
              <RouterLink to="/register" class="text-paper/70 transition-colors hover:text-paper">Register a salon</RouterLink>
            </li>
          </ul>
        </div>
      </div>

      <div class="mt-14 flex flex-col items-start justify-between gap-4 border-t border-paper/10 pt-8 sm:flex-row sm:items-center">
        <p class="text-sm text-paper/50">© 2026 SalonHub. All rights reserved.</p>
        <RouterLink
          to="/register"
          class="inline-flex items-center gap-2 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
        >
          Register a salon
          <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14M13 6l6 6-6 6" />
          </svg>
        </RouterLink>
      </div>
    </div>
  </footer>
</template>
