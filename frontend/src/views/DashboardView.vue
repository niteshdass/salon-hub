<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const loadError = ref('')

const user = computed(() => authStore.user)
const organization = computed(() => authStore.organization)

const stats = [
  { key: 'bookings', label: 'Bookings', value: 0, accent: 'bg-indigo-50 text-indigo-600' },
  { key: 'staff', label: 'Staff', value: 0, accent: 'bg-emerald-50 text-emerald-600' },
  { key: 'services', label: 'Services', value: 0, accent: 'bg-amber-50 text-amber-600' },
  { key: 'customers', label: 'Customers', value: 0, accent: 'bg-sky-50 text-sky-600' },
]

onMounted(async () => {
  if (!authStore.user) {
    try {
      await authStore.fetchMe()
    } catch {
      loadError.value = 'Could not load your account. Please try again.'
    }
  }
})

async function onLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <div class="min-h-screen">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
        <div class="flex items-center gap-3">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white"
          >
            S
          </div>
          <span class="text-lg font-semibold text-slate-900">SalonHub</span>
        </div>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
          @click="onLogout"
        >
          Logout
        </button>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
      <div
        v-if="loadError"
        class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ loadError }}
      </div>

      <section class="mb-8">
        <h1 class="text-2xl font-bold text-slate-900">
          Welcome, {{ user?.name || 'there' }}
        </h1>
        <div class="mt-2 flex flex-wrap items-center gap-3">
          <p v-if="organization" class="text-slate-500">
            {{ organization.name }}
          </p>
          <span
            v-if="organization?.primary_domain"
            class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600"
          >
            {{ organization.primary_domain }}
          </span>
          <span
            v-if="organization?.subscription_plan"
            class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium uppercase tracking-wide text-indigo-700"
          >
            {{ organization.subscription_plan }}
          </span>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div
          v-for="stat in stats"
          :key="stat.key"
          class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
        >
          <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">{{ stat.label }}</p>
            <span
              class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold"
              :class="stat.accent"
            >
              {{ stat.label.charAt(0) }}
            </span>
          </div>
          <p class="mt-3 text-3xl font-bold text-slate-900">{{ stat.value }}</p>
          <p class="mt-1 text-xs text-slate-400">No data yet</p>
        </div>
      </section>
    </main>
  </div>
</template>
