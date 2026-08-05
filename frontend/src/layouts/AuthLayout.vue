<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { APP_DOMAIN, resolveSlugFromHost } from '@/lib/tenantHost'

// Shared shell for the SaaS auth pages (login, register, password, verify).
// It carries the marketing brand — warm paper, Fraunces display, terracotta
// accent — so signing in never feels like leaving the site, and it always
// keeps a way back to the landing page.
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})

// nginx-salon.conf serves the whole SPA from <slug>.APP_DOMAIN, so /login,
// /register and /terms are live on every salon's host — and there `/` is that
// salon's shopfront (see the landing route in router/index.js), not the
// SalonHub marketing site. A RouterLink to '/' therefore lands on the salon's
// own page; the footer link below is worse still, because its visible text is
// the apex domain, so it states a destination it does not go to.
//
// On a salon host these three need an absolute href to the apex. On the apex
// and the dashboard host (and in dev, where resolveSlugFromHost is null) the
// RouterLink is correct and stays.
const onSalonHost = computed(() => resolveSlugFromHost() !== null)

const home = computed(() =>
  onSalonHost.value
    ? { is: 'a', attrs: { href: `${window.location.protocol}//${APP_DOMAIN}/` } }
    : { is: RouterLink, attrs: { to: '/' } },
)
</script>

<template>
  <div class="relative flex min-h-screen flex-col bg-paper text-ink">
    <!-- Warm atmospheric glows, same recipe as the hero. Declared first and
         left un-layered so the positioned header/main/footer below paint on
         top of it (a negative z-index would sink it behind bg-paper). -->
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
      <div class="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-brand-200/40 blur-3xl"></div>
      <div class="absolute -bottom-32 -left-32 h-96 w-96 rounded-full bg-rose-200/30 blur-3xl"></div>
    </div>

    <header class="relative border-b border-brand-100/70">
      <nav class="mx-auto flex h-18 max-w-6xl items-center justify-between px-6 lg:px-8">
        <component :is="home.is" v-bind="home.attrs" class="group flex items-center gap-2.5" aria-label="SalonHub home">
          <span
            class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white shadow-sm shadow-brand-500/30 transition-transform duration-300 group-hover:-rotate-6"
          >
            <span class="font-display text-lg font-semibold leading-none">S</span>
          </span>
          <span class="font-display text-xl font-semibold tracking-tight text-ink">SalonHub</span>
        </component>

        <component
          :is="home.is"
          v-bind="home.attrs"
          class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-semibold text-ink/65 transition-colors hover:bg-brand-50 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
        >
          <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M11 18l-6-6 6-6" />
          </svg>
          Back to SalonHub
        </component>
      </nav>
    </header>

    <main class="relative flex flex-1 items-center justify-center px-6 py-12 lg:px-8">
      <div class="w-full max-w-md">
        <div
          class="rounded-3xl border border-brand-100 bg-white p-8 shadow-2xl shadow-ink/10 ring-1 ring-brand-50"
        >
          <div class="mb-7 text-center">
            <h1 class="font-display text-3xl font-semibold tracking-tight text-ink">{{ title }}</h1>
            <p v-if="subtitle" class="mt-2 text-sm leading-relaxed text-ink/60">{{ subtitle }}</p>
            <slot name="subtitle" />
          </div>

          <slot />
        </div>

        <div v-if="$slots.footer" class="mt-6 text-center text-sm text-ink/60">
          <slot name="footer" />
        </div>
      </div>
    </main>

    <footer class="relative border-t border-brand-100/70">
      <div
        class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-6 py-6 text-sm text-ink/45 sm:flex-row lg:px-8"
      >
        <p>© 2026 SalonHub</p>
        <component
          :is="home.is"
          v-bind="home.attrs"
          class="font-medium text-brand-700 transition-colors hover:text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper"
        >
          {{ APP_DOMAIN }}
        </component>
      </div>
    </footer>
  </div>
</template>
