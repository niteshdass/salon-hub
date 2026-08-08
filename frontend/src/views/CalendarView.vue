<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'

const router = useRouter()
const authStore = useAuthStore()

// Staff only ever receive their own rows from the API, so the staff filter
// is pointless for them.
const canFilterByStaff = computed(() => authStore.canManageOperations)

/* ------------------------------ Date helpers ---------------------------- */
// Everything is local-time; the API speaks plain Y-m-d strings.
function toKey(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}
function fromKey(key) {
  const [y, m, d] = key.split('-').map(Number)
  return new Date(y, m - 1, d)
}
function addDays(date, days) {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}
// Weeks start on Monday — salons think in "Mon–Sun" working weeks.
function startOfWeek(date) {
  const day = (date.getDay() + 6) % 7
  return addDays(date, -day)
}
function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

const MODES = [
  { key: 'month', label: 'Month' },
  { key: 'week', label: 'Week' },
  { key: 'day', label: 'Day' },
]
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const STATUS_META = {
  pending: { label: 'Pending', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-400', chip: 'border-amber-200 bg-amber-50 text-amber-900' },
  confirmed: { label: 'Confirmed', badge: 'bg-blue-50 text-blue-700', dot: 'bg-blue-500', chip: 'border-blue-200 bg-blue-50 text-blue-900' },
  completed: { label: 'Completed', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500', chip: 'border-emerald-200 bg-emerald-50 text-emerald-900' },
  cancelled: { label: 'Cancelled', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-300', chip: 'border-slate-200 bg-slate-50 text-slate-500 line-through' },
  no_show: { label: 'No-show', badge: 'bg-rose-50 text-rose-700', dot: 'bg-rose-500', chip: 'border-rose-200 bg-rose-50 text-rose-900' },
}
function statusMeta(status) {
  return STATUS_META[status] || { label: status || '—', badge: 'bg-slate-100 text-slate-600', dot: 'bg-slate-300', chip: 'border-slate-200 bg-slate-50 text-slate-700' }
}

/* -------------------------------- State --------------------------------- */
const mode = ref('month')
const cursor = ref(new Date())
const staffFilter = ref('')
const branchFilter = ref('')

const appointments = ref([])
const loading = ref(false)
const listError = ref('')

const staffOptions = ref([])
const branchOptions = ref([])

const selected = ref(null)

const todayKey = toKey(new Date())

/** Inclusive [from, to] the current mode needs — the month grid spills into
 *  the neighbouring months, so it fetches whole weeks. */
const range = computed(() => {
  const c = cursor.value
  if (mode.value === 'day') {
    return { from: toKey(c), to: toKey(c) }
  }
  if (mode.value === 'week') {
    const start = startOfWeek(c)
    return { from: toKey(start), to: toKey(addDays(start, 6)) }
  }
  // The grid is always 6 rows, so fetch 42 days — a 5-week month would
  // otherwise render a trailing week with no data behind it.
  const gridStart = startOfWeek(startOfMonth(c))
  return { from: toKey(gridStart), to: toKey(addDays(gridStart, 41)) }
})

const periodLabel = computed(() => {
  const c = cursor.value
  if (mode.value === 'day') {
    return c.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  }
  if (mode.value === 'week') {
    const start = startOfWeek(c)
    const end = addDays(start, 6)
    const sameMonth = start.getMonth() === end.getMonth()
    const startLabel = start.toLocaleDateString(undefined, { day: 'numeric', month: sameMonth ? undefined : 'short' })
    const endLabel = end.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
    return `${startLabel} – ${endLabel}`
  }
  return c.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
})

/** Appointments keyed by Y-m-d and sorted by start time, so each cell is a
 *  plain lookup instead of a per-cell filter. */
const byDate = computed(() => {
  const map = {}
  for (const appt of appointments.value) {
    ;(map[appt.booking_date] ||= []).push(appt)
  }
  for (const key of Object.keys(map)) {
    map[key].sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''))
  }
  return map
})

function dayAppointments(key) {
  return byDate.value[key] || []
}

const monthGrid = computed(() => {
  const start = startOfWeek(startOfMonth(cursor.value))
  const month = cursor.value.getMonth()
  return Array.from({ length: 6 }, (_, week) =>
    Array.from({ length: 7 }, (_, day) => {
      const date = addDays(start, week * 7 + day)
      const key = toKey(date)
      return {
        key,
        date,
        dayNumber: date.getDate(),
        inMonth: date.getMonth() === month,
        isToday: key === todayKey,
      }
    }),
  )
})

const weekDays = computed(() => {
  const start = startOfWeek(cursor.value)
  return Array.from({ length: 7 }, (_, i) => {
    const date = addDays(start, i)
    const key = toKey(date)
    return { key, date, isToday: key === todayKey, label: WEEKDAYS[i] }
  })
})

const dayKey = computed(() => toKey(cursor.value))

const periodCount = computed(() => appointments.value.length)

/* -------------------------------- Loading -------------------------------- */
async function loadAppointments() {
  loading.value = true
  listError.value = ''
  try {
    const params = { ...range.value }
    if (staffFilter.value) params.staff_id = staffFilter.value
    if (branchFilter.value) params.branch_id = branchFilter.value
    const { data } = await api.get('/appointments', { params })
    appointments.value = data.data ?? []
  } catch (err) {
    listError.value = parseApiError(err).message
    appointments.value = []
  } finally {
    loading.value = false
  }
}

async function loadFilters() {
  const requests = [api.get('/branches')]
  if (canFilterByStaff.value) requests.push(api.get('/staff'))
  try {
    const [branches, staff] = await Promise.all(requests)
    branchOptions.value = branches.data.data ?? []
    staffOptions.value = staff?.data.data ?? []
  } catch {
    // Filters are a convenience — the grid still works without them.
  }
}

/* ------------------------------- Navigation ------------------------------ */
function step(direction) {
  const c = new Date(cursor.value)
  if (mode.value === 'month') c.setMonth(c.getMonth() + direction)
  else if (mode.value === 'week') c.setDate(c.getDate() + 7 * direction)
  else c.setDate(c.getDate() + direction)
  cursor.value = c
}

function goToday() {
  cursor.value = new Date()
}

function setMode(next) {
  mode.value = next
}

/** Clicking a month cell drills into that day, which is how people actually
 *  use a month grid — scan, then zoom. */
function openDay(key) {
  cursor.value = fromKey(key)
  mode.value = 'day'
}

function bookOn(key) {
  router.push({ path: '/appointments', query: { date: key } })
}

watch([mode, cursor, staffFilter, branchFilter], loadAppointments)

onMounted(async () => {
  await Promise.all([loadFilters(), loadAppointments()])
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Calendar</h1>
        <p class="mt-1 text-sm text-slate-500">
          {{ periodCount }} appointment{{ periodCount === 1 ? '' : 's' }} in this view.
        </p>
      </div>

      <!-- Mode switch -->
      <div class="inline-flex rounded-lg bg-slate-100 p-1">
        <button
          v-for="m in MODES"
          :key="m.key"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="mode === m.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
          @click="setMode(m.key)"
        >
          {{ m.label }}
        </button>
      </div>
    </div>

    <!-- Toolbar: period nav + filters -->
    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white p-2 text-slate-600 transition hover:bg-slate-50"
          aria-label="Previous period"
          @click="step(-1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
          @click="goToday"
        >
          Today
        </button>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white p-2 text-slate-600 transition hover:bg-slate-50"
          aria-label="Next period"
          @click="step(1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
          </svg>
        </button>
      </div>

      <p class="text-base font-semibold text-slate-900">{{ periodLabel }}</p>

      <div class="ml-auto flex flex-wrap items-center gap-2">
        <select
          v-if="canFilterByStaff"
          v-model="staffFilter"
          class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
        >
          <option value="">All staff</option>
          <option v-for="member in staffOptions" :key="member.id" :value="member.id">{{ member.name }}</option>
        </select>
        <select
          v-model="branchFilter"
          class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
        >
          <option value="">All branches</option>
          <option v-for="branch in branchOptions" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
        </select>
      </div>
    </div>

    <p v-if="listError" class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200">
      {{ listError }}
    </p>

    <!-- Month -->
    <div v-if="mode === 'month'" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50">
        <div v-for="day in WEEKDAYS" :key="day" class="px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
          {{ day }}
        </div>
      </div>
      <div v-for="(week, i) in monthGrid" :key="i" class="grid grid-cols-7 border-b border-slate-100 last:border-b-0">
        <div
          v-for="cell in week"
          :key="cell.key"
          class="min-h-[7rem] border-r border-slate-100 p-1.5 last:border-r-0 transition hover:bg-slate-50/70"
          :class="cell.inMonth ? 'bg-white' : 'bg-slate-50/60'"
        >
          <button
            type="button"
            class="mb-1 flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium transition"
            :class="cell.isToday
              ? 'bg-indigo-600 text-white'
              : cell.inMonth
                ? 'text-slate-700 hover:bg-slate-200'
                : 'text-slate-400 hover:bg-slate-200'"
            @click="openDay(cell.key)"
          >
            {{ cell.dayNumber }}
          </button>

          <ul class="space-y-1">
            <li v-for="appt in dayAppointments(cell.key).slice(0, 3)" :key="appt.id">
              <button
                type="button"
                class="w-full truncate rounded-md border px-1.5 py-1 text-left text-[11px] leading-tight transition hover:brightness-95"
                :class="statusMeta(appt.status).chip"
                @click="selected = appt"
              >
                <span class="font-semibold">{{ appt.start_time }}</span>
                {{ appt.customer?.name || 'Walk-in' }}
              </button>
            </li>
            <li v-if="dayAppointments(cell.key).length > 3">
              <button
                type="button"
                class="w-full rounded-md px-1.5 py-0.5 text-left text-[11px] font-medium text-indigo-600 hover:text-indigo-800"
                @click="openDay(cell.key)"
              >
                +{{ dayAppointments(cell.key).length - 3 }} more
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Week -->
    <div v-else-if="mode === 'week'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-7">
      <div
        v-for="day in weekDays"
        :key="day.key"
        class="rounded-2xl bg-white p-3 shadow-sm ring-1 transition"
        :class="day.isToday ? 'ring-2 ring-indigo-400' : 'ring-slate-200'"
      >
        <button type="button" class="mb-2 flex w-full items-baseline justify-between" @click="openDay(day.key)">
          <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ day.label }}</span>
          <span
            class="text-sm font-bold"
            :class="day.isToday ? 'text-indigo-600' : 'text-slate-900'"
          >
            {{ day.date.getDate() }}
          </span>
        </button>

        <p v-if="dayAppointments(day.key).length === 0" class="py-3 text-center text-xs text-slate-400">—</p>
        <ul v-else class="space-y-1.5">
          <li v-for="appt in dayAppointments(day.key)" :key="appt.id">
            <button
              type="button"
              class="w-full rounded-lg border px-2 py-1.5 text-left text-xs transition hover:brightness-95"
              :class="statusMeta(appt.status).chip"
              @click="selected = appt"
            >
              <span class="block font-semibold">{{ appt.start_time }}–{{ appt.end_time }}</span>
              <span class="block truncate">{{ appt.customer?.name || 'Walk-in' }}</span>
              <span class="block truncate opacity-70">{{ (appt.services || []).map((s) => s.name).join(', ') }}</span>
            </button>
          </li>
        </ul>
      </div>
    </div>

    <!-- Day -->
    <div v-else class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p v-if="loading" class="py-10 text-center text-sm text-slate-500">Loading…</p>
      <div v-else-if="dayAppointments(dayKey).length === 0" class="py-12 text-center">
        <p class="text-sm font-medium text-slate-900">Nothing booked</p>
        <p class="mt-1 text-sm text-slate-500">This day is completely free.</p>
        <button
          type="button"
          class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
          @click="bookOn(dayKey)"
        >
          Open in appointments
        </button>
      </div>
      <ol v-else class="space-y-2">
        <li
          v-for="appt in dayAppointments(dayKey)"
          :key="appt.id"
          class="flex items-center gap-4 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50"
        >
          <div class="w-20 shrink-0 text-right">
            <p class="text-sm font-semibold text-slate-900">{{ appt.start_time }}</p>
            <p class="text-xs text-slate-400">{{ appt.end_time }}</p>
          </div>
          <span class="h-10 w-1 shrink-0 rounded-full" :class="statusMeta(appt.status).dot"></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-slate-900">{{ appt.customer?.name || 'Walk-in' }}</p>
            <p class="truncate text-xs text-slate-500">
              {{ (appt.services || []).map((s) => s.name).join(', ') }} · {{ appt.staff?.name }}
              <span v-if="appt.branch"> · {{ appt.branch.name }}</span>
            </p>
          </div>
          <span
            class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="statusMeta(appt.status).badge"
          >
            {{ statusMeta(appt.status).label }}
          </span>
          <button
            type="button"
            class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            @click="selected = appt"
          >
            Details
          </button>
        </li>
      </ol>
    </div>

    <p v-if="loading && mode !== 'day'" class="mt-3 text-center text-sm text-slate-500">Loading…</p>

    <!-- Detail -->
    <Modal v-if="selected" title="Appointment" @close="selected = null">
      <div class="space-y-3 text-sm">
        <div class="flex items-center justify-between">
          <span class="font-semibold text-slate-900">
            {{ selected.booking_date }} · {{ selected.start_time }}–{{ selected.end_time }}
          </span>
          <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statusMeta(selected.status).badge">
            {{ statusMeta(selected.status).label }}
          </span>
        </div>
        <dl class="divide-y divide-slate-100">
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-slate-500">Customer</dt>
            <dd class="text-right text-slate-900">
              {{ selected.customer?.name || 'Walk-in' }}
              <span v-if="selected.customer?.phone" class="block text-xs text-slate-500">{{ selected.customer.phone }}</span>
            </dd>
          </div>
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-slate-500">Service</dt>
            <dd class="text-right text-slate-900">{{ (selected.services || []).map((s) => s.name).join(', ') || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-slate-500">Staff</dt>
            <dd class="text-right text-slate-900">{{ selected.staff?.name || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-slate-500">Branch</dt>
            <dd class="text-right text-slate-900">{{ selected.branch?.name || '—' }}</dd>
          </div>
          <div v-if="selected.notes" class="py-2">
            <dt class="text-slate-500">Notes</dt>
            <dd class="mt-1 text-slate-900">{{ selected.notes }}</dd>
          </div>
        </dl>
        <button
          type="button"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
          @click="bookOn(selected.booking_date)"
        >
          Manage in appointments
        </button>
      </div>
    </Modal>
  </div>
</template>
