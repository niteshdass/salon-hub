<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import PageHeader from '@/components/PageHeader.vue'
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
  { key: 'pending', label: 'Pending', classes: 'sh-badge-pending' },
  { key: 'confirmed', label: 'Confirmed', classes: 'sh-badge-confirmed' },
  { key: 'completed', label: 'Completed', classes: 'sh-badge-completed' },
  { key: 'cancelled', label: 'Cancelled', classes: 'sh-badge-cancelled' },
  { key: 'no_show', label: 'No-show', classes: 'sh-badge-no-show' },
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

// The one line the page header carries, built from the payload the tiles
// already render — nothing extra is fetched for it.
const todaySummary = computed(() => {
  const name = user.value?.name || 'there'
  if (!today.value) return `Welcome back, ${name}.`
  const count = today.value.bookings
  return `Welcome back, ${name} — ${count} booking${count === 1 ? '' : 's'} on today's sheet.`
})

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
      accent: 'bg-accent-50 text-accent-600',
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

    <PageHeader title="Dashboard" :subtitle="todaySummary">
      <template #actions>
        <a
          v-if="organization?.slug"
          :href="`/book/${organization.slug}`"
          target="_blank"
          rel="noopener"
          class="sh-btn"
        >
          View booking page
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
          </svg>
        </a>
      </template>
    </PageHeader>

    <!-- Tiles -->
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <template v-if="loading">
        <div
          v-for="n in 4"
          :key="n"
          class="sh-card h-32 animate-pulse"
        ></div>
      </template>
      <template v-else>
        <div v-for="tile in tiles" :key="tile.key" class="sh-card p-5">
          <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-ink/60">{{ tile.label }}</p>
            <span
              class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-semibold"
              :class="tile.accent"
            >
              {{ tile.label.charAt(0) }}
            </span>
          </div>
          <p class="mt-3 truncate font-display text-3xl text-ink">{{ tile.value }}</p>
          <p class="mt-1 text-xs text-ink/40">{{ tile.hint }}</p>
        </div>
      </template>
    </section>

    <!-- Today's status breakdown -->
    <section v-if="today" class="sh-card mt-6 p-5">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-display text-xl text-ink">Today at a glance</h2>
        <RouterLink
          :to="{ path: '/appointments', query: { date: today.date } }"
          class="sh-btn sh-btn-ghost"
        >
          Open today
        </RouterLink>
      </div>
      <div class="mt-4 flex flex-wrap gap-2">
        <span
          v-for="chip in STATUS_CHIPS"
          :key="chip.key"
          class="sh-badge"
          :class="[chip.classes, today.by_status[chip.key] ? '' : 'opacity-50']"
        >
          {{ chip.label }}
          <span class="font-bold">{{ today.by_status[chip.key] }}</span>
        </span>
      </div>
    </section>

    <!-- Upcoming -->
    <section class="sh-card mt-6">
      <div class="flex items-center justify-between border-b border-ink/10 px-5 py-4">
        <h2 class="font-display text-xl text-ink">Next up</h2>
        <RouterLink to="/calendar" class="sh-btn sh-btn-ghost">View calendar</RouterLink>
      </div>

      <div v-if="loading" class="space-y-3 p-5">
        <div v-for="n in 3" :key="n" class="h-12 animate-pulse rounded-lg bg-ink/5"></div>
      </div>

      <p v-else-if="!upcoming.length" class="px-5 py-10 text-center text-sm text-ink/55">
        Nothing booked from here on.
      </p>

      <div v-else class="overflow-x-auto px-1 pb-1">
        <table class="sh-table">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Booking</th>
              <th class="text-right">When</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="appt in upcoming" :key="appt.id">
              <td class="font-medium text-ink">{{ appt.customer?.name || 'Walk-in' }}</td>
              <td class="text-ink/60">
                {{ (appt.services || []).map((s) => s.name).join(', ') }}
                <span v-if="!isStaff && appt.staff?.name"> · {{ appt.staff.name }}</span>
                <span v-if="appt.branch?.name"> · {{ appt.branch.name }}</span>
              </td>
              <td class="text-right whitespace-nowrap">
                <span class="font-semibold text-ink">{{ appt.start_time }}</span>
                <span class="block text-xs text-ink/50">{{ dayLabel(appt.booking_date) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
