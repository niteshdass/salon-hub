<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import PageHeader from '@/components/PageHeader.vue'

const authStore = useAuthStore()
const currency = computed(() => authStore.organization?.currency || 'USD')

const report = ref(null)
const loading = ref(false)
const loadError = ref('')

// Active preset key, or 'custom' when the from/to inputs are edited.
const activePreset = ref('30d')
const range = reactive({ from: '', to: '' })

// Statuses keep their fixed semantic hues (the sh-badge modifiers) rather
// than following the tenant accent.
const STATUS_META = {
  pending: { label: 'Pending', class: 'sh-badge-pending' },
  confirmed: { label: 'Confirmed', class: 'sh-badge-confirmed' },
  completed: { label: 'Completed', class: 'sh-badge-completed' },
  cancelled: { label: 'Cancelled', class: 'sh-badge-cancelled' },
  no_show: { label: 'No-show', class: 'sh-badge-no-show' },
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

// The header states the window the figures cover; the picker below changes it.
const rangeLabel = computed(() =>
  range.from && range.to
    ? `Earnings, services, staff, and bookings from ${range.from} to ${range.to}.`
    : 'Earnings, services, staff, and bookings at a glance.',
)

function deltaClass(pct) {
  if (pct === null || pct === undefined) return 'text-ink/40'
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
    <PageHeader title="Reports" :subtitle="rangeLabel">
      <template #actions>
        <!-- Preset switch, same segmented control the calendar uses. -->
        <div class="sh-card inline-flex flex-wrap rounded-full bg-paper p-1 shadow-none">
          <button
            v-for="preset in PRESETS"
            :key="preset.key"
            type="button"
            class="rounded-full px-3 py-1.5 text-sm font-medium transition"
            :class="activePreset === preset.key ? 'bg-white text-ink shadow-sm' : 'text-ink/55 hover:text-ink'"
            @click="applyPreset(preset.key)"
          >
            {{ preset.label }}
          </button>
        </div>
      </template>
    </PageHeader>

    <!-- Custom range -->
    <div class="sh-card mb-5 flex flex-wrap items-end gap-3 p-4">
      <div class="w-44">
        <label class="sh-label">From</label>
        <input v-model="range.from" type="date" class="sh-input" @change="applyCustom" />
      </div>
      <div class="w-44">
        <label class="sh-label">To</label>
        <input v-model="range.to" type="date" class="sh-input" @change="applyCustom" />
      </div>
    </div>

    <div v-if="loadError" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </div>

    <div v-if="loading" class="sh-card p-10 text-center text-sm text-ink/60">
      Loading reports…
    </div>

    <template v-else-if="report">
      <!-- Summary cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="sh-card p-5">
          <p class="text-sm text-ink/60">Earned</p>
          <p class="mt-1 font-display text-2xl text-ink">{{ money(report.summary.earned) }}</p>
          <p class="mt-1 text-xs font-medium" :class="deltaClass(delta.earned_pct)">{{ deltaText(delta.earned_pct) }}</p>
        </div>
        <div class="sh-card p-5">
          <p class="text-sm text-ink/60">Bookings</p>
          <p class="mt-1 font-display text-2xl text-ink">{{ report.summary.bookings }}</p>
          <p class="mt-1 text-xs font-medium" :class="deltaClass(delta.bookings_pct)">{{ deltaText(delta.bookings_pct) }}</p>
        </div>
        <div class="sh-card p-5">
          <p class="text-sm text-ink/60">Avg ticket</p>
          <p class="mt-1 font-display text-2xl text-ink">{{ money(report.summary.avg_ticket) }}</p>
          <p class="mt-1 text-xs text-ink/40">completed bookings</p>
        </div>
      </div>

      <!-- Revenue chart -->
      <div class="sh-card mt-6 p-5">
        <h2 class="font-display text-lg text-ink">Revenue over time</h2>
        <div v-if="report.revenue.points.length" class="mt-4 flex h-48 items-stretch gap-1 overflow-x-auto">
          <div
            v-for="point in report.revenue.points"
            :key="point.period"
            class="group relative flex h-full min-w-[8px] flex-1 flex-col items-center justify-end"
            :title="`${point.label}: ${money(point.earned)}`"
          >
            <div
              class="w-full rounded-t bg-accent-500 transition group-hover:bg-accent-600"
              :style="{ height: `${Math.max(2, (Number(point.earned) / maxEarned) * 100)}%` }"
            ></div>
          </div>
        </div>
        <p v-else class="mt-4 text-sm text-ink/60">No revenue in this range.</p>
        <p class="mt-2 text-xs text-ink/50">Grouped by {{ report.revenue.granularity }}.</p>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Top services. Three read-only columns fit a 390px card, so this
             one keeps a single table branch and scrolls inside its own box. -->
        <div class="sh-card p-5">
          <h2 class="font-display text-lg text-ink">Top services</h2>
          <div v-if="report.top_services.length" class="mt-3 overflow-x-auto">
            <table class="sh-table">
              <thead>
                <tr>
                  <th>Service</th>
                  <th class="text-right">Services booked</th>
                  <th class="text-right">Earned</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in report.top_services" :key="row.service_id">
                  <td>{{ row.name }} <span class="text-xs text-ink/40">({{ row.share_pct }}%)</span></td>
                  <td class="text-right text-ink/60">{{ row.bookings }}</td>
                  <td class="text-right font-medium text-ink">{{ money(row.earned) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="mt-3 text-sm text-ink/60">No completed bookings in this range.</p>
        </div>

        <!-- Staff performance. Four read-only columns, no row controls — same
             judgement as above. -->
        <div class="sh-card p-5">
          <h2 class="font-display text-lg text-ink">Staff performance</h2>
          <div v-if="report.staff.length" class="mt-3 overflow-x-auto">
            <table class="sh-table">
              <thead>
                <tr>
                  <th>Staff</th>
                  <th class="text-right">Bookings</th>
                  <th class="text-right">Earned</th>
                  <th class="text-right">Rating</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in report.staff" :key="row.staff_id">
                  <td>{{ row.name }}</td>
                  <td class="text-right text-ink/60">{{ row.bookings }}</td>
                  <td class="text-right font-medium text-ink">{{ money(row.earned) }}</td>
                  <td class="text-right text-ink/60">
                    <span v-if="row.rating.average !== null">★ {{ row.rating.average }} <span class="text-xs text-ink/40">({{ row.rating.count }})</span></span>
                    <span v-else class="text-ink/30">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="mt-3 text-sm text-ink/60">No completed bookings in this range.</p>
        </div>
      </div>

      <!-- Bookings breakdown -->
      <div class="sh-card mt-6 p-5">
        <h2 class="font-display text-lg text-ink">Bookings breakdown</h2>
        <div class="mt-3 flex flex-wrap gap-2">
          <span
            v-for="(count, key) in report.bookings.by_status"
            :key="key"
            class="sh-badge"
            :class="STATUS_META[key]?.class || 'sh-badge-no-show'"
          >
            {{ STATUS_META[key]?.label || key }}: {{ count }}
          </span>
        </div>
        <div class="mt-4 flex flex-wrap gap-6 text-sm">
          <div>
            <p class="text-xs uppercase tracking-wider text-ink/50">Busiest day</p>
            <p class="mt-0.5 font-medium text-ink">
              {{ report.bookings.busiest_day ? `${WEEKDAYS[report.bookings.busiest_day.weekday]} (${report.bookings.busiest_day.count})` : '—' }}
            </p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wider text-ink/50">Busiest hour</p>
            <p class="mt-0.5 font-medium text-ink">
              {{ report.bookings.busiest_hour ? `${String(report.bookings.busiest_hour.hour).padStart(2, '0')}:00 (${report.bookings.busiest_hour.count})` : '—' }}
            </p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
