<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const organization = computed(() => authStore.organization)
const sidebarOpen = ref(false)

// Two letters is enough to recognise your own salon at a glance.
const orgInitials = computed(
  () =>
    (organization.value?.name || '')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0].toUpperCase())
      .join('') || 'S'
)

const planLabel = computed(() => {
  const plan = organization.value?.subscription_plan
  return plan ? `${plan[0].toUpperCase()}${plan.slice(1)} plan` : ''
})

// Unverified accounts keep full access — the banner is a nudge, and the
// user has to be loaded before we can judge either way.
const showVerifyBanner = computed(() => !!authStore.user && !authStore.emailVerified)
const resendState = ref('idle')
const resendMessage = ref('')

async function resendVerification() {
  resendState.value = 'sending'
  try {
    const { data } = await api.post('/auth/email/resend')
    resendMessage.value = data.message
    resendState.value = 'sent'
    if (data.verified) await authStore.fetchMe().catch(() => {})
  } catch (err) {
    resendMessage.value = parseApiError(err).message
    resendState.value = 'failed'
  }
}

// `d` holds a heroicons-style outline path so every nav item renders through
// one <svg> template instead of a bespoke icon block each. Items carry the
// roles their policy allows, so the sidebar never offers a page the API
// would refuse.
const navGroups = [
  {
    label: 'Operate',
    items: [
      {
        name: 'Dashboard',
        to: '/dashboard',
        d: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
      },
      {
        name: 'Appointments',
        to: '/appointments',
        d: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
      },
      {
        name: 'Calendar',
        to: '/calendar',
        d: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75M8.25 11.25h.008v.008H8.25v-.008zm3.75 0h.008v.008H12v-.008zm3.75 0h.008v.008h-.008v-.008zM8.25 15h.008v.008H8.25V15zm3.75 0h.008v.008H12V15zm3.75 0h.008v.008h-.008V15z',
      },
    ],
  },
  {
    label: 'Business',
    items: [
      {
        name: 'Branches',
        to: '/branches',
        d: 'M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72l1.189-1.19A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72M6.75 12h.008v.008H6.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
      },
      {
        name: 'Services',
        to: '/services',
        d: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z M6 6h.008v.008H6V6z',
      },
      {
        name: 'Staff',
        to: '/staff',
        d: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
      },
      {
        name: 'Customers',
        to: '/customers',
        d: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
      },
    ],
  },
  {
    label: 'Insight',
    items: [
      {
        name: 'Reports',
        to: '/reports',
        roles: ['owner', 'manager'],
        d: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
      },
      {
        name: 'Finance',
        to: '/finance',
        roles: ['owner'],
        d: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
      },
      {
        name: 'Reviews',
        to: '/reviews',
        d: 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
      },
    ],
  },
  {
    label: 'Presence',
    items: [
      {
        name: 'Gallery',
        to: '/gallery',
        roles: ['owner', 'manager'],
        d: 'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
      },
      {
        name: 'Settings',
        to: '/settings',
        roles: ['owner'],
        d: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
      },
    ],
  },
]

// A group with nothing in it for this role renders no heading at all.
const visibleGroups = computed(() =>
  navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.roles || item.roles.includes(authStore.role)),
    }))
    .filter((group) => group.items.length > 0)
)

// The bar names the page the sidebar highlighted, so the two never disagree.
// Match the item exactly or as a path prefix, never as a string prefix, so a
// future `/reports-archive` cannot label itself Reports.
const pageLabel = computed(() => {
  const match = navGroups
    .flatMap((group) => group.items)
    .find((item) => route.path === item.to || route.path.startsWith(`${item.to}/`))
  return match?.name ?? ''
})

// Read once at setup: the date only turns over between sessions, and nothing
// here would make a computed recalculate anyway.
const today = new Date().toLocaleDateString(undefined, {
  weekday: 'long',
  month: 'short',
  day: 'numeric',
})

onMounted(async () => {
  if (!authStore.user) {
    try {
      await authStore.fetchMe()
    } catch {
      // The 401 interceptor handles auth failures; ignore transient errors here.
    }
  }
})

async function onLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <div class="min-h-screen bg-paper">
    <!-- Mobile backdrop -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-30 bg-ink/50 lg:hidden"
      @click="sidebarOpen = false"
    ></div>

    <!-- Sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-ink transition-transform duration-200 ease-in-out lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-20 shrink-0 items-center gap-3 px-6">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-accent-500 text-sm font-bold text-accent-fg">
          S
        </div>
        <span class="font-display text-xl font-semibold text-white">SalonHub</span>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-4">
        <section v-for="group in visibleGroups" :key="group.label" :aria-label="group.label">
          <p
            data-nav-group
            class="px-3 pb-2 text-[0.68rem] font-semibold tracking-[0.18em] text-white/35 uppercase"
          >
            {{ group.label }}
          </p>

          <RouterLink
            v-for="item in group.items"
            :key="item.to"
            :to="item.to"
            class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/65 transition hover:bg-white/10 hover:text-white"
            active-class="!bg-accent-500 !text-accent-fg"
            @click="sidebarOpen = false"
          >
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.d" />
            </svg>
            {{ item.name }}
          </RouterLink>
        </section>
      </nav>

      <div data-org-card class="mt-auto flex items-center gap-3 border-t border-white/10 px-4 py-4">
        <!-- Until `fetchMe` lands there is no salon to name, and a lone
             fallback initial over two blank lines reads as a broken card. -->
        <template v-if="organization">
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white">
            {{ orgInitials }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ organization.name }}</p>
            <p class="text-xs text-white/45">{{ planLabel }}</p>
          </div>
        </template>
        <button
          type="button"
          class="shrink-0 text-xs font-medium text-white/55 transition hover:text-white"
          @click="onLogout"
        >
          Logout
        </button>
      </div>
    </aside>

    <!-- Content column -->
    <div class="lg:pl-64">
      <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-ink/10 bg-paper/85 px-4 backdrop-blur sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
          <button
            type="button"
            class="rounded-lg p-2 text-ink/60 transition hover:bg-ink/5 hover:text-ink lg:hidden"
            aria-label="Open navigation"
            @click="sidebarOpen = true"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
            </svg>
          </button>
          <span class="truncate text-sm font-medium text-ink">{{ pageLabel }}</span>
          <span aria-hidden="true" class="hidden text-ink/20 sm:inline">|</span>
          <span class="hidden truncate text-sm text-ink/55 sm:inline">{{ today }}</span>
        </div>
      </header>

      <main class="px-4 py-8 sm:px-6 lg:px-10">
        <div class="mx-auto max-w-6xl">
          <div
            v-if="showVerifyBanner"
            class="mb-6 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="flex items-start gap-2">
              <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
              </svg>
              <span>
                <template v-if="resendState === 'idle'">
                  Confirm <span class="font-medium">{{ authStore.user.email }}</span> to secure your
                  account — check your inbox for the verification link.
                </template>
                <template v-else>{{ resendMessage }}</template>
              </span>
            </div>
            <button
              v-if="resendState !== 'sent'"
              type="button"
              :disabled="resendState === 'sending'"
              class="sh-btn shrink-0 border-amber-300 text-amber-900 hover:bg-amber-100"
              @click="resendVerification"
            >
              {{ resendState === 'sending' ? 'Sending…' : 'Resend email' }}
            </button>
          </div>

          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
