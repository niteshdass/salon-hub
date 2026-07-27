<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'

const authStore = useAuthStore()
const currency = computed(() => authStore.organization?.currency || 'USD')

const report = ref(null)
const loading = ref(false)
const loadError = ref('')

// Active preset key, or 'custom' when the from/to inputs are edited.
const activePreset = ref('30d')
const range = reactive({ from: '', to: '' })

const STATUS_META = {
  pending: { label: 'Pending', class: 'bg-amber-100 text-amber-700' },
  confirmed: { label: 'Confirmed', class: 'bg-blue-100 text-blue-700' },
  completed: { label: 'Completed', class: 'bg-emerald-100 text-emerald-700' },
  cancelled: { label: 'Cancelled', class: 'bg-slate-200 text-slate-600' },
  no_show: { label: 'No-show', class: 'bg-rose-100 text-rose-700' },
}
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

function ymd(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

// Each preset resolves to concrete from/to dates on the client.
function presetRange(key) {
  const today = new Date()
  const to = ymd(today)
  if (key === '7d') {
    const from = new Date(today); from.setDate(from.getDate() - 6)
    return { from: ymd(from), to }
  }
  if (key === '30d') {
    const from = new Date(today); from.setDate(from.getDate() - 29)
    return { from: ymd(from), to }
  }
  if (key === 'month') {
    return { from: ymd(new Date(today.getFullYear(), today.getMonth(), 1)), to }
  }
  if (key === 'lastMonth') {
    const first = new Date(today.getFullYear(), today.getMonth() - 1, 1)
    const last = new Date(today.getFullYear(), today.getMonth(), 0)
    return { from: ymd(first), to: ymd(last) }
  }
  // year
  return { from: ymd(new Date(today.getFullYear(), 0, 1)), to }
}

const PRESETS = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: 'month', label: 'This month' },
  { key: 'lastMonth', label: 'Last month' },
  { key: 'year', label: 'This year' },
]

function money(value) {
  const amount = Number(value || 0)
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value, maximumFractionDigits: 2 }).format(amount)
  } catch {
    return `${currency.value} ${amount.toFixed(2)}`
  }
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/reports', { params: { from: range.from, to: range.to } })
    report.value = data.data
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load reports.').message
  } finally {
    loading.value = false
  }
}

function applyPreset(key) {
  activePreset.value = key
  Object.assign(range, presetRange(key))
  load()
}

function applyCustom() {
  if (!range.from || !range.to) return
  activePreset.value = 'custom'
  load()
}

// Chart geometry: normalise bars against the tallest earning bucket.
const maxEarned = computed(() => {
  const points = report.value?.revenue?.points || []
  return Math.max(1, ...points.map((p) => Number(p.earned)))
})

const delta = computed(() => report.value?.summary?.delta || {})

function deltaClass(pct) {
  if (pct === null || pct === undefined) return 'text-slate-400'
  return pct >= 0 ? 'text-emerald-600' : 'text-rose-600'
}
function deltaText(pct) {
  if (pct === null || pct === undefined) return '—'
  return `${pct >= 0 ? '+' : ''}${pct}% vs prev period`
}

onMounted(() => applyPreset('30d'))
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-900">Reports</h1>
      <p class="mt-1 text-sm text-slate-500">Earnings, services, staff, and bookings at a glance.</p>
    </div>

    <!-- Range picker -->
    <div class="mb-6 flex flex-wrap items-end gap-3">
      <div class="inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm">
        <button
          v-for="preset in PRESETS"
          :key="preset.key"
          type="button"
          class="rounded-md px-3 py-1.5 font-medium transition"
          :class="activePreset === preset.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          @click="applyPreset(preset.key)"
        >
          {{ preset.label }}
        </button>
      </div>
      <div class="flex items-end gap-2">
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
          <input v-model="range.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" @change="applyCustom" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
          <input v-model="range.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" @change="applyCustom" />
        </div>
      </div>
    </div>

    <div v-if="loadError" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </div>

    <div v-if="loading" class="rounded-2xl bg-white p-10 text-center text-sm text-slate-500 ring-1 ring-slate-200">
      Loading reports…
    </div>

    <template v-else-if="report">
      <!-- Summary cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">Earned</p>
          <p class="mt-1 text-2xl font-bold text-slate-900">{{ money(report.summary.earned) }}</p>
          <p class="mt-1 text-xs font-medium" :class="deltaClass(delta.earned_pct)">{{ deltaText(delta.earned_pct) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">Bookings</p>
          <p class="mt-1 text-2xl font-bold text-slate-900">{{ report.summary.bookings }}</p>
          <p class="mt-1 text-xs font-medium" :class="deltaClass(delta.bookings_pct)">{{ deltaText(delta.bookings_pct) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">Avg ticket</p>
          <p class="mt-1 text-2xl font-bold text-slate-900">{{ money(report.summary.avg_ticket) }}</p>
          <p class="mt-1 text-xs text-slate-400">completed bookings</p>
        </div>
      </div>

      <!-- Revenue chart -->
      <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Revenue over time</h2>
        <div v-if="report.revenue.points.length" class="mt-4 flex h-48 items-end gap-1 overflow-x-auto">
          <div
            v-for="point in report.revenue.points"
            :key="point.period"
            class="group relative flex min-w-[8px] flex-1 flex-col items-center justify-end"
            :title="`${point.label}: ${money(point.earned)}`"
          >
            <div
              class="w-full rounded-t bg-indigo-500 transition group-hover:bg-indigo-600"
              :style="{ height: `${Math.max(2, (Number(point.earned) / maxEarned) * 100)}%` }"
            ></div>
          </div>
        </div>
        <p v-else class="mt-4 text-sm text-slate-500">No revenue in this range.</p>
        <p class="mt-2 text-xs text-slate-400">Grouped by {{ report.revenue.granularity }}.</p>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Top services -->
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-semibold text-slate-900">Top services</h2>
          <table v-if="report.top_services.length" class="mt-3 w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                <th class="pb-2">Service</th>
                <th class="pb-2 text-right">Bookings</th>
                <th class="pb-2 text-right">Earned</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in report.top_services" :key="row.service_id">
                <td class="py-2 text-slate-900">{{ row.name }} <span class="text-xs text-slate-400">({{ row.share_pct }}%)</span></td>
                <td class="py-2 text-right text-slate-600">{{ row.bookings }}</td>
                <td class="py-2 text-right font-medium text-slate-900">{{ money(row.earned) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="mt-3 text-sm text-slate-500">No completed bookings in this range.</p>
        </div>

        <!-- Staff performance -->
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-semibold text-slate-900">Staff performance</h2>
          <table v-if="report.staff.length" class="mt-3 w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                <th class="pb-2">Staff</th>
                <th class="pb-2 text-right">Bookings</th>
                <th class="pb-2 text-right">Earned</th>
                <th class="pb-2 text-right">Rating</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in report.staff" :key="row.staff_id">
                <td class="py-2 text-slate-900">{{ row.name }}</td>
                <td class="py-2 text-right text-slate-600">{{ row.bookings }}</td>
                <td class="py-2 text-right font-medium text-slate-900">{{ money(row.earned) }}</td>
                <td class="py-2 text-right text-slate-600">
                  <span v-if="row.rating.average !== null">★ {{ row.rating.average }} <span class="text-xs text-slate-400">({{ row.rating.count }})</span></span>
                  <span v-else class="text-slate-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="mt-3 text-sm text-slate-500">No completed bookings in this range.</p>
        </div>
      </div>

      <!-- Bookings breakdown -->
      <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Bookings breakdown</h2>
        <div class="mt-3 flex flex-wrap gap-2">
          <span
            v-for="(count, key) in report.bookings.by_status"
            :key="key"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
            :class="STATUS_META[key]?.class || 'bg-slate-200 text-slate-600'"
          >
            {{ STATUS_META[key]?.label || key }}: {{ count }}
          </span>
        </div>
        <div class="mt-4 flex flex-wrap gap-6 text-sm">
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Busiest day</p>
            <p class="mt-0.5 font-medium text-slate-900">
              {{ report.bookings.busiest_day ? `${WEEKDAYS[report.bookings.busiest_day.weekday]} (${report.bookings.busiest_day.count})` : '—' }}
            </p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Busiest hour</p>
            <p class="mt-0.5 font-medium text-slate-900">
              {{ report.bookings.busiest_hour ? `${String(report.bookings.busiest_hour.hour).padStart(2, '0')}:00 (${report.bookings.busiest_hour.count})` : '—' }}
            </p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
