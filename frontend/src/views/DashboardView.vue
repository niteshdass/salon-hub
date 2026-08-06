<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import SubdomainBanner from '@/components/SubdomainBanner.vue'
import SetupChecklistCard from '@/components/SetupChecklistCard.vue'

const authStore = useAuthStore()

const user = computed(() => authStore.user)
const organization = computed(() => authStore.organization)
const isStaff = computed(() => authStore.isStaff)

const loading = ref(true)
const loadError = ref('')
const data = ref(null)

// Mirrors the API's zero-filled breakdown, so the chip row never shifts.
const STATUS_CHIPS = [
  { key: 'pending', label: 'Pending', classes: 'bg-amber-50 text-amber-700 ring-amber-200' },
  { key: 'confirmed', label: 'Confirmed', classes: 'bg-blue-50 text-blue-700 ring-blue-200' },
  { key: 'completed', label: 'Completed', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  { key: 'cancelled', label: 'Cancelled', classes: 'bg-slate-100 text-slate-600 ring-slate-200' },
  { key: 'no_show', label: 'No-show', classes: 'bg-rose-50 text-rose-700 ring-rose-200' },
]

const today = computed(() => data.value?.today ?? null)
const totals = computed(() => data.value?.totals ?? null)
const upcoming = computed(() => data.value?.upcoming ?? [])

const currency = computed(() => organization.value?.currency || 'USD')

function money(amount) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currency.value,
      maximumFractionDigits: 2,
    }).format(amount)
  } catch {
    // An unknown ISO code should degrade, not blank out the tile.
    return `${currency.value} ${Number(amount).toFixed(2)}`
  }
}

/** The headline tiles. Revenue is owner/manager-only and the API simply
 *  omits it for staff, so the tile follows the payload. */
const tiles = computed(() => {
  if (!today.value) return []

  const list = [
    {
      key: 'bookings',
      label: "Today's bookings",
      value: today.value.bookings,
      hint: isStaff.value ? 'On your schedule' : 'Across the salon',
      accent: 'bg-indigo-50 text-indigo-600',
    },
  ]

  if (today.value.revenue !== undefined) {
    list.push({
      key: 'revenue',
      label: "Today's revenue",
      value: money(today.value.revenue),
      hint: 'Completed bookings only',
      accent: 'bg-emerald-50 text-emerald-600',
    })
  }

  if (totals.value) {
    list.push(
      {
        key: 'customers',
        label: 'Customers',
        value: totals.value.customers,
        hint: 'On the books',
        accent: 'bg-sky-50 text-sky-600',
      },
      {
        key: 'services',
        label: 'Services',
        value: totals.value.services,
        hint: `${totals.value.staff} staff · ${totals.value.branches} branch${totals.value.branches === 1 ? '' : 'es'}`,
        accent: 'bg-amber-50 text-amber-600',
      },
    )
  }

  return list
})

function dayLabel(dateStr) {
  const [y, m, d] = dateStr.split('-').map(Number)
  const date = new Date(y, m - 1, d)
  const todayDate = new Date()
  const same = (a, b) =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()

  if (same(date, todayDate)) return 'Today'
  const tomorrow = new Date(todayDate)
  tomorrow.setDate(tomorrow.getDate() + 1)
  if (same(date, tomorrow)) return 'Tomorrow'

  return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data: payload } = await api.get('/dashboard')
    data.value = payload
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load your dashboard.').message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (!authStore.user) {
    await authStore.fetchMe().catch(() => {})
  }
  await load()
})
</script>

<template>
  <div>
    <SubdomainBanner />
    <SetupChecklistCard class="mb-6" />

    <div
      v-if="loadError"
      class="mb-6 flex items-center justify-between gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
    >
      <span>{{ loadError }}</span>
      <button type="button" class="font-medium underline" @click="load">Retry</button>
    </div>

    <section class="mb-8">
      <h1 class="text-2xl font-bold text-slate-900">Welcome, {{ user?.name || 'there' }}</h1>
      <div class="mt-2 flex flex-wrap items-center gap-3">
        <p v-if="organization" class="text-slate-500">{{ organization.name }}</p>
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

    <!-- Tiles -->
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <template v-if="loading">
        <div
          v-for="n in 4"
          :key="n"
          class="h-32 animate-pulse rounded-2xl bg-white shadow-sm ring-1 ring-slate-200"
        ></div>
      </template>
      <template v-else>
        <div
          v-for="tile in tiles"
          :key="tile.key"
          class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
        >
          <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">{{ tile.label }}</p>
            <span
              class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold"
              :class="tile.accent"
            >
              {{ tile.label.charAt(0) }}
            </span>
          </div>
          <p class="mt-3 truncate text-3xl font-bold text-slate-900">{{ tile.value }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ tile.hint }}</p>
        </div>
      </template>
    </section>

    <!-- Today's status breakdown -->
    <section v-if="today" class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold text-slate-900">Today at a glance</h2>
        <RouterLink
          :to="{ path: '/appointments', query: { date: today.date } }"
          class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
        >
          Open today
        </RouterLink>
      </div>
      <div class="mt-4 flex flex-wrap gap-2">
        <span
          v-for="chip in STATUS_CHIPS"
          :key="chip.key"
          class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium ring-1"
          :class="[chip.classes, today.by_status[chip.key] ? '' : 'opacity-50']"
        >
          {{ chip.label }}
          <span class="font-bold">{{ today.by_status[chip.key] }}</span>
        </span>
      </div>
    </section>

    <!-- Upcoming -->
    <section class="mt-6 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-900">Next up</h2>
        <RouterLink to="/calendar" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
          View calendar
        </RouterLink>
      </div>

      <div v-if="loading" class="space-y-3 p-5">
        <div v-for="n in 3" :key="n" class="h-12 animate-pulse rounded-lg bg-slate-100"></div>
      </div>

      <p v-else-if="!upcoming.length" class="px-5 py-10 text-center text-sm text-slate-500">
        Nothing booked from here on.
      </p>

      <ul v-else class="divide-y divide-slate-100">
        <li
          v-for="appt in upcoming"
          :key="appt.id"
          class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-900">
              {{ appt.customer?.name || 'Walk-in' }}
            </p>
            <p class="truncate text-xs text-slate-500">
              {{ appt.service?.name }}
              <span v-if="!isStaff && appt.staff?.name"> · {{ appt.staff.name }}</span>
              <span v-if="appt.branch?.name"> · {{ appt.branch.name }}</span>
            </p>
          </div>
          <div class="text-right">
            <p class="text-sm font-semibold text-slate-900">{{ appt.start_time }}</p>
            <p class="text-xs text-slate-500">{{ dayLabel(appt.booking_date) }}</p>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>
