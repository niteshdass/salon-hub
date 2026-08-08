<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import PageHeader from '@/components/PageHeader.vue'

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

// `badge` is the sh-badge modifier (fixed semantic hues — a status must not
// read differently per salon). `chip` is the grid event, which follows the
// tenant accent so the calendar reads as one surface; cancelled and no-show
// still break away, because "this booking is off" has to survive a glance.
const STATUS_META = {
  pending: { label: 'Pending', badge: 'sh-badge-pending', dot: 'bg-amber-400', chip: 'border-l-2 border-accent-300 bg-accent-50 text-ink' },
  confirmed: { label: 'Confirmed', badge: 'sh-badge-confirmed', dot: 'bg-sky-500', chip: 'border-l-2 border-accent-500 bg-accent-50 text-ink' },
  completed: { label: 'Completed', badge: 'sh-badge-completed', dot: 'bg-emerald-500', chip: 'border-l-2 border-accent-500 bg-accent-50 text-ink/60' },
  cancelled: { label: 'Cancelled', badge: 'sh-badge-cancelled', dot: 'bg-rose-500', chip: 'border-l-2 border-rose-400 bg-rose-50 text-ink/60 line-through' },
  no_show: { label: 'No-show', badge: 'sh-badge-no-show', dot: 'bg-ink/25', chip: 'border-l-2 border-ink/20 bg-ink/5 text-ink' },
}
function statusMeta(status) {
  return STATUS_META[status] || { label: status || '—', badge: 'sh-badge-no-show', dot: 'bg-ink/20', chip: 'border-l-2 border-ink/20 bg-ink/5 text-ink' }
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
    <PageHeader
      title="Calendar"
      :subtitle="`${periodCount} appointment${periodCount === 1 ? '' : 's'} in this view.`"
    >
      <template #actions>
        <!-- Mode switch -->
        <div class="sh-card inline-flex rounded-full bg-paper p-1 shadow-none">
          <button
            v-for="m in MODES"
            :key="m.key"
            type="button"
            class="rounded-full px-3 py-1.5 text-sm font-medium transition"
            :class="mode === m.key ? 'bg-white text-ink shadow-sm' : 'text-ink/55 hover:text-ink'"
            @click="setMode(m.key)"
          >
            {{ m.label }}
          </button>
        </div>
      </template>
    </PageHeader>

    <!-- Toolbar: period nav + filters -->
    <div class="sh-card mb-5 flex flex-wrap items-center gap-3 p-4">
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="sh-btn p-2"
          aria-label="Previous period"
          @click="step(-1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
        </button>
        <button
          type="button"
          class="sh-btn"
          @click="goToday"
        >
          Today
        </button>
        <button
          type="button"
          class="sh-btn p-2"
          aria-label="Next period"
          @click="step(1)"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
          </svg>
        </button>
      </div>

      <p class="font-display text-lg text-ink">{{ periodLabel }}</p>

      <div class="ml-auto flex flex-wrap items-center gap-2">
        <select v-if="canFilterByStaff" v-model="staffFilter" class="sh-input w-auto">
          <option value="">All staff</option>
          <option v-for="member in staffOptions" :key="member.id" :value="member.id">{{ member.name }}</option>
        </select>
        <select v-model="branchFilter" class="sh-input w-auto">
          <option value="">All branches</option>
          <option v-for="branch in branchOptions" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
        </select>
      </div>
    </div>

    <p v-if="listError" class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200">
      {{ listError }}
    </p>

    <!-- Month -->
    <div v-if="mode === 'month'" class="sh-card overflow-hidden">
      <div class="grid grid-cols-7 border-b border-ink/10 bg-paper">
        <div v-for="day in WEEKDAYS" :key="day" class="px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wider text-ink/50">
          {{ day }}
        </div>
      </div>
      <div v-for="(week, i) in monthGrid" :key="i" class="grid grid-cols-7 border-b border-ink/10 last:border-b-0">
        <div
          v-for="cell in week"
          :key="cell.key"
          class="min-h-[7rem] border-r border-ink/10 p-1.5 last:border-r-0 transition hover:bg-paper/70"
          :class="cell.inMonth ? 'bg-white' : 'bg-paper/60'"
        >
          <button
            type="button"
            class="mb-1 flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium transition"
            :class="cell.isToday
              ? 'bg-accent-500 text-accent-fg'
              : cell.inMonth
                ? 'text-ink/75 hover:bg-ink/10'
                : 'text-ink/40 hover:bg-ink/10'"
            @click="openDay(cell.key)"
          >
            {{ cell.dayNumber }}
          </button>

          <ul class="space-y-1">
            <li v-for="appt in dayAppointments(cell.key).slice(0, 3)" :key="appt.id">
              <button
                type="button"
                class="w-full truncate rounded-r-md px-1.5 py-1 text-left text-[11px] leading-tight transition hover:brightness-95"
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
                class="w-full rounded-md px-1.5 py-0.5 text-left text-[11px] font-medium text-accent-600 hover:text-accent-700"
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
        class="sh-card p-3 transition"
        :class="day.isToday ? 'ring-2 ring-accent-400' : ''"
      >
        <button type="button" class="mb-2 flex w-full items-baseline justify-between" @click="openDay(day.key)">
          <span class="text-xs font-semibold uppercase tracking-wider text-ink/50">{{ day.label }}</span>
          <span
            class="text-sm font-bold"
            :class="day.isToday ? 'text-accent-600' : 'text-ink'"
          >
            {{ day.date.getDate() }}
          </span>
        </button>

        <p v-if="dayAppointments(day.key).length === 0" class="py-3 text-center text-xs text-ink/40">—</p>
        <ul v-else class="space-y-1.5">
          <li v-for="appt in dayAppointments(day.key)" :key="appt.id">
            <button
              type="button"
              class="w-full rounded-r-lg px-2 py-1.5 text-left text-xs transition hover:brightness-95"
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
    <template v-else>
      <p v-if="loading" class="sh-card px-5 py-10 text-center text-sm text-ink/60">Loading…</p>
      <div v-else-if="dayAppointments(dayKey).length === 0" class="sh-empty">
        <p class="font-medium text-ink">Nothing booked</p>
        <p class="mt-1">This day is completely free.</p>
        <button type="button" class="sh-btn sh-btn-primary mt-4" @click="bookOn(dayKey)">
          Open in appointments
        </button>
      </div>
      <ol v-else class="sh-card space-y-2 p-5">
        <li
          v-for="appt in dayAppointments(dayKey)"
          :key="appt.id"
          class="flex items-center gap-4 rounded-xl border border-ink/10 p-3 transition hover:bg-paper"
        >
          <div class="w-20 shrink-0 text-right">
            <p class="text-sm font-semibold text-ink">{{ appt.start_time }}</p>
            <p class="text-xs text-ink/40">{{ appt.end_time }}</p>
          </div>
          <span class="h-10 w-1 shrink-0 rounded-full" :class="statusMeta(appt.status).dot"></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink">{{ appt.customer?.name || 'Walk-in' }}</p>
            <p class="truncate text-xs text-ink/60">
              {{ (appt.services || []).map((s) => s.name).join(', ') }} · {{ appt.staff?.name }}
              <span v-if="appt.branch"> · {{ appt.branch.name }}</span>
            </p>
          </div>
          <span class="sh-badge shrink-0" :class="statusMeta(appt.status).badge">
            {{ statusMeta(appt.status).label }}
          </span>
          <button
            type="button"
            class="sh-btn shrink-0 px-3 py-1.5 text-xs"
            @click="selected = appt"
          >
            Details
          </button>
        </li>
      </ol>
    </template>

    <p v-if="loading && mode !== 'day'" class="mt-3 text-center text-sm text-ink/60">Loading…</p>

    <!-- Detail -->
    <Modal v-if="selected" title="Appointment" @close="selected = null">
      <div class="space-y-3 text-sm">
        <div class="flex items-center justify-between">
          <span class="font-semibold text-ink">
            {{ selected.booking_date }} · {{ selected.start_time }}–{{ selected.end_time }}
          </span>
          <span class="sh-badge" :class="statusMeta(selected.status).badge">
            {{ statusMeta(selected.status).label }}
          </span>
        </div>
        <dl class="divide-y divide-ink/10">
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-ink/60">Customer</dt>
            <dd class="text-right text-ink">
              {{ selected.customer?.name || 'Walk-in' }}
              <span v-if="selected.customer?.phone" class="block text-xs text-ink/60">{{ selected.customer.phone }}</span>
            </dd>
          </div>
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-ink/60">Service</dt>
            <dd class="text-right text-ink">{{ (selected.services || []).map((s) => s.name).join(', ') || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-ink/60">Staff</dt>
            <dd class="text-right text-ink">{{ selected.staff?.name || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-2">
            <dt class="text-ink/60">Branch</dt>
            <dd class="text-right text-ink">{{ selected.branch?.name || '—' }}</dd>
          </div>
          <div v-if="selected.notes" class="py-2">
            <dt class="text-ink/60">Notes</dt>
            <dd class="mt-1 text-ink">{{ selected.notes }}</dd>
          </div>
        </dl>
        <button
          type="button"
          class="sh-btn sh-btn-primary w-full"
          @click="bookOn(selected.booking_date)"
        >
          Manage in appointments
        </button>
      </div>
    </Modal>
  </div>
</template>
