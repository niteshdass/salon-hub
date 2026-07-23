<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const loadError = ref('')

const user = computed(() => authStore.user)
const organization = computed(() => authStore.organization)

const counts = reactive({
  bookings: 0,
  staff: 0,
  services: 0,
  customers: 0,
})

const stats = computed(() => [
  { key: 'bookings', label: 'Bookings', value: counts.bookings, accent: 'bg-indigo-50 text-indigo-600' },
  { key: 'staff', label: 'Staff', value: counts.staff, accent: 'bg-emerald-50 text-emerald-600' },
  { key: 'services', label: 'Services', value: counts.services, accent: 'bg-amber-50 text-amber-600' },
  { key: 'customers', label: 'Customers', value: counts.customers, accent: 'bg-sky-50 text-sky-600' },
])

onMounted(async () => {
  if (!authStore.user) {
    try {
      await authStore.fetchMe()
    } catch {
      loadError.value = 'Could not load your account. Please try again.'
    }
  }

  // Today's date (local) for the bookings count.
  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`

  // Best-effort live counts; a failed tile just stays at 0.
  const [staffRes, servicesRes, bookingsRes, customersRes] = await Promise.allSettled([
    api.get('/staff'),
    api.get('/services'),
    api.get('/appointments', { params: { date: today } }),
    api.get('/customers'),
  ])
  if (staffRes.status === 'fulfilled') {
    counts.staff = staffRes.value.data?.data?.length || 0
  }
  if (servicesRes.status === 'fulfilled') {
    counts.services = servicesRes.value.data?.data?.length || 0
  }
  if (bookingsRes.status === 'fulfilled') {
    counts.bookings = bookingsRes.value.data?.data?.length || 0
  }
  if (customersRes.status === 'fulfilled') {
    counts.customers = customersRes.value.data?.data?.length || 0
  }
})
</script>

<template>
  <div>
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
        <a
          v-if="organization?.slug"
          :href="`/book/${organization.slug}`"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 transition hover:text-indigo-800"
        >
          View booking page
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
          </svg>
        </a>
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
        <p class="mt-1 text-xs text-slate-400">
          {{ stat.value ? 'Total' : 'No data yet' }}
        </p>
      </div>
    </section>
  </div>
</template>
