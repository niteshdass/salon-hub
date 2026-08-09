<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink } from 'vue-router'
import { useSessionLink } from '@/lib/sessionLink'

// A signed-in visitor gets the way back into their own area instead of the
// "Log in" they have already done.
const session = useSessionLink()

const open = ref(false)
const scrolled = ref(false)

const links = [
  { label: 'Features', href: '#features' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'FAQ', href: '#faq' },
  { label: 'Contact', href: '#contact' },
]

function onScroll() {
  scrolled.value = window.scrollY > 8
}

onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
})
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))
</script>

<template>
  <header
    class="sticky top-0 z-50 transition-colors duration-300"
    :class="scrolled || open
      ? 'border-b border-brand-100/80 bg-paper/85 backdrop-blur-md'
      : 'border-b border-transparent bg-paper/0'"
  >
    <nav class="mx-auto flex h-18 max-w-6xl items-center justify-between px-6 lg:px-8">
      <!-- Wordmark -->
      <a href="#top" class="group flex items-center gap-2.5" aria-label="SalonHub home">
        <span
          class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white shadow-sm shadow-brand-500/30 transition-transform duration-300 group-hover:-rotate-6"
        >
          <span class="font-display text-lg font-semibold leading-none">S</span>
        </span>
        <span class="font-display text-xl font-semibold tracking-tight text-ink">SalonHub</span>
      </a>

      <!-- Desktop anchor links -->
      <div class="hidden items-center gap-1 md:flex">
        <a
          v-for="link in links"
          :key="link.href"
          :href="link.href"
          class="rounded-full px-3.5 py-2 text-sm font-medium text-ink/65 transition-colors hover:bg-brand-50 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
        >
          {{ link.label }}
        </a>
        <RouterLink
          to="/salons"
          class="rounded-full px-3.5 py-2 text-sm font-medium text-ink/65 transition-colors hover:bg-brand-50 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
        >
          Find a salon
        </RouterLink>
        <!--
          Customers sit on this side of the bar, beside "Find a salon" — the
          actions on the right belong to salon owners. Hidden once a session
          exists, because that session's own link is already in the actions.
        -->
        <RouterLink
          v-if="!session"
          to="/account/login"
          class="rounded-full px-3.5 py-2 text-sm font-medium text-ink/65 transition-colors hover:bg-brand-50 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
        >
          My bookings
        </RouterLink>
      </div>

      <!-- Desktop actions -->
      <div class="hidden items-center gap-2 md:flex">
        <RouterLink
          v-if="session"
          :to="session.to"
          class="inline-flex items-center rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-500/25 transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-600 hover:shadow-brand-500/35 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper"
        >
          {{ session.label }}
        </RouterLink>
        <template v-else>
          <RouterLink
            to="/login"
            class="rounded-full px-4 py-2 text-sm font-semibold text-ink/75 transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
          >
            Salon log in
          </RouterLink>
          <RouterLink
            to="/register"
            class="inline-flex items-center rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-500/25 transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-600 hover:shadow-brand-500/35 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper"
          >
            Register a salon
          </RouterLink>
        </template>
      </div>

      <!-- Mobile: keep primary CTA + toggle -->
      <div class="flex items-center gap-2 md:hidden">
        <RouterLink
          :to="session ? session.to : '/register'"
          class="inline-flex items-center rounded-full bg-brand-500 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-brand-500/25 transition-colors hover:bg-brand-600"
        >
          {{ session ? session.label : 'Register a salon' }}
        </RouterLink>
        <button
          type="button"
          class="grid h-10 w-10 place-items-center rounded-xl border border-brand-100 text-ink/70 transition-colors hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
          :aria-expanded="open"
          aria-label="Toggle navigation menu"
          @click="open = !open"
        >
          <svg v-if="!open" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
            <path d="M4 7h16M4 12h16M4 17h16" />
          </svg>
          <svg v-else viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </div>
    </nav>

    <!-- Mobile dropdown panel -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="-translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="-translate-y-2 opacity-0"
    >
      <div v-if="open" class="border-t border-brand-100/70 bg-paper/95 backdrop-blur-md md:hidden">
        <div class="mx-auto flex max-w-6xl flex-col gap-1 px-6 py-4">
          <a
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            class="rounded-xl px-3 py-2.5 text-base font-medium text-ink/80 transition-colors hover:bg-brand-50 hover:text-ink"
            @click="open = false"
          >
            {{ link.label }}
          </a>
          <RouterLink
            to="/salons"
            class="rounded-xl px-3 py-2.5 text-base font-medium text-ink/80 transition-colors hover:bg-brand-50 hover:text-ink"
            @click="open = false"
          >
            Find a salon
          </RouterLink>
          <RouterLink
            v-if="!session"
            to="/account/login"
            class="rounded-xl px-3 py-2.5 text-base font-semibold text-brand-700 transition-colors hover:bg-brand-50"
            @click="open = false"
          >
            My bookings
          </RouterLink>
          <RouterLink
            :to="session ? session.to : '/login'"
            class="rounded-xl px-3 py-2.5 text-base font-semibold text-brand-700 transition-colors hover:bg-brand-50"
            @click="open = false"
          >
            {{ session ? session.label : 'Salon log in' }}
          </RouterLink>
        </div>
      </div>
    </Transition>
  </header>
</template>
