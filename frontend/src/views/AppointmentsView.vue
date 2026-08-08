<script>
// Whom to offer for a multi-service visit: one staff member performs the
// whole thing back-to-back, so they must cover every selected service — an
// intersection, not a union. Exported so the rule has a test independent of
// the DOM; the backend enforces the same intersection, this just mirrors it.
export function staffWhoCanDoAll(staff, serviceIds) {
  if (!serviceIds.length) return staff
  return staff.filter((member) =>
    serviceIds.every((id) => (member.services || []).some((sv) => sv.id === Number(id))),
  )
}
</script>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PaymentModal from '@/components/PaymentModal.vue'

// Local (not UTC) YYYY-MM-DD for the current day.
function todayStr() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

// Status -> label + badge classes (amber / blue / green / slate / red).
const STATUS_META = {
  pending: { label: 'Pending', badge: 'bg-amber-50 text-amber-700' },
  confirmed: { label: 'Confirmed', badge: 'bg-blue-50 text-blue-700' },
  completed: { label: 'Completed', badge: 'bg-emerald-50 text-emerald-700' },
  cancelled: { label: 'Cancelled', badge: 'bg-slate-100 text-slate-600' },
  no_show: { label: 'No-show', badge: 'bg-rose-50 text-rose-700' },
}
const STATUS_KEYS = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show']
// The one-tap actions offered per row (excludes "pending").
const QUICK_ACTIONS = ['confirmed', 'completed', 'cancelled', 'no_show']

function statusLabel(status) {
  return STATUS_META[status]?.label || status || '—'
}
function statusBadge(status) {
  return STATUS_META[status]?.badge || 'bg-slate-100 text-slate-600'
}

/* ------------------------------ Day list ------------------------------ */
// Staff see only their own schedule (the API filters the list) and may
// only move an appointment through its statuses.
const authStore = useAuthStore()
const canWrite = computed(() => authStore.canManageOperations)

// The calendar hands off here with ?date=Y-m-d.
const route = useRoute()
const initialDate = /^\d{4}-\d{2}-\d{2}$/.test(route.query.date || '') ? route.query.date : todayStr()

const selectedDate = ref(initialDate)
const statusFilter = ref('')

const appointments = ref([])
const loading = ref(false)
const listError = ref('')
const statusUpdatingId = ref(null)

const sortedAppointments = computed(() =>
  [...appointments.value].sort((a, b) =>
    (a.start_time || '').localeCompare(b.start_time || ''),
  ),
)

async function loadAppointments() {
  loading.value = true
  listError.value = ''
  try {
    const params = { date: selectedDate.value }
    if (statusFilter.value) params.status = statusFilter.value
    const { data } = await api.get('/appointments', { params })
    appointments.value = data.data || []
  } catch (err) {
    listError.value = parseApiError(err, 'Could not load appointments.').message
  } finally {
    loading.value = false
  }
}

watch([selectedDate, statusFilter], loadAppointments)

async function setStatus(appt, status) {
  statusUpdatingId.value = appt.id
  listError.value = ''
  try {
    await api.patch(`/appointments/${appt.id}`, { status })
    await loadAppointments()
  } catch (err) {
    listError.value = parseApiError(err, 'Could not update status.').message
  } finally {
    statusUpdatingId.value = null
  }
}

/* --------------------- Supporting lists (lazy) --------------------- */
const branchList = ref([])
const serviceList = ref([])
const staffList = ref([])
const customerList = ref([])
const optionsLoaded = ref(false)
const optionsLoading = ref(false)

async function ensureOptions() {
  if (optionsLoaded.value) return
  optionsLoading.value = true
  const [b, s, st, c] = await Promise.allSettled([
    api.get('/branches'),
    api.get('/services'),
    api.get('/staff'),
    api.get('/customers'),
  ])
  if (b.status === 'fulfilled') branchList.value = b.value.data?.data || []
  if (s.status === 'fulfilled') serviceList.value = s.value.data?.data || []
  if (st.status === 'fulfilled') staffList.value = st.value.data?.data || []
  if (c.status === 'fulfilled') customerList.value = c.value.data?.data || []
  optionsLoaded.value = true
  optionsLoading.value = false
}

/* ------------------------------ Form ------------------------------ */
const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const formMessage = ref('')
const formErrors = ref({})

const form = reactive({
  branch_id: '',
  service_ids: [],
  staff_id: '',
  customerMode: 'existing', // existing | new
  customer_id: '',
  new_customer: { name: '', phone: '', email: '' },
  booking_date: '',
  start_time: '',
  status: 'pending',
  notes: '',
})

// When services are picked, prefer staff who can do every one of them; if
// none can, show all (mirrors the single-service fallback this replaces).
const filteredStaff = computed(() => {
  const matched = staffWhoCanDoAll(staffList.value, form.service_ids)
  return matched.length ? matched : staffList.value
})
const noStaffMatch = computed(() => {
  if (!form.service_ids.length) return false
  return staffWhoCanDoAll(staffList.value, form.service_ids).length === 0
})

function serviceLabel(svc) {
  return svc.duration != null ? `${svc.name} (${svc.duration} min)` : svc.name
}

// Running total for the checkbox list, kept in sync with whatever is ticked.
const selectedServices = computed(() =>
  serviceList.value.filter((svc) => form.service_ids.includes(svc.id)),
)
const formDuration = computed(() =>
  selectedServices.value.reduce((sum, svc) => sum + Number(svc.duration || 0), 0),
)
const formTotal = computed(() =>
  selectedServices.value.reduce((sum, svc) => sum + Number(svc.price || 0), 0),
)

// Laravel keys an array-level failure (required/min:1) as `service_ids`, but
// a per-item failure (an id that doesn't belong to this salon) as
// `service_ids.0`, `service_ids.1`, ... — both shapes need checking.
const serviceIdsErrors = computed(() => {
  if (formErrors.value.service_ids) return formErrors.value.service_ids
  const perItem = Object.keys(formErrors.value)
    .filter((key) => key.startsWith('service_ids.'))
    .flatMap((key) => formErrors.value[key])
  return perItem.length ? perItem : null
})

function resetForm() {
  Object.assign(form, {
    branch_id: '',
    service_ids: [],
    staff_id: '',
    customerMode: 'existing',
    customer_id: '',
    booking_date: selectedDate.value,
    start_time: '',
    status: 'pending',
    notes: '',
  })
  form.new_customer = { name: '', phone: '', email: '' }
  formErrors.value = {}
  formMessage.value = ''
}

async function openCreate() {
  editing.value = null
  resetForm()
  showForm.value = true
  await ensureOptions()
  // Auto-select the branch when there is exactly one.
  if (branchList.value.length === 1) {
    form.branch_id = branchList.value[0].id
  }
}

async function openEdit(appt) {
  editing.value = appt
  resetForm()
  showForm.value = true
  await ensureOptions()
  Object.assign(form, {
    branch_id: appt.branch?.id ?? '',
    service_ids: (appt.services || []).map((s) => s.id),
    staff_id: appt.staff?.id ?? '',
    customerMode: 'existing',
    customer_id: appt.customer?.id ?? '',
    booking_date: appt.booking_date || selectedDate.value,
    start_time: appt.start_time || '',
    status: appt.status || 'pending',
    notes: appt.notes || '',
  })
}

function closeForm() {
  showForm.value = false
  editing.value = null
}

async function submitForm() {
  saving.value = true
  formErrors.value = {}
  formMessage.value = ''

  const payload = {
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    service_ids: form.service_ids.map(Number),
    staff_id: form.staff_id ? Number(form.staff_id) : null,
    booking_date: form.booking_date,
    start_time: form.start_time,
    status: form.status,
    notes: form.notes || null,
  }

  // Send either an existing customer_id or an inline new_customer block.
  if (!editing.value && form.customerMode === 'new') {
    payload.new_customer = {
      name: form.new_customer.name,
      phone: form.new_customer.phone || null,
      email: form.new_customer.email || null,
    }
  } else {
    payload.customer_id = form.customer_id ? Number(form.customer_id) : null
  }

  try {
    if (editing.value) {
      await api.patch(`/appointments/${editing.value.id}`, payload)
    } else {
      await api.post('/appointments', payload)
    }
    closeForm()
    await loadAppointments()
  } catch (err) {
    // 422 conflicts (double-booking) + validation errors stay in the modal.
    const parsed = parseApiError(err)
    formErrors.value = parsed.errors
    formMessage.value = parsed.message
  } finally {
    saving.value = false
  }
}

/* ------------------------------ Delete ------------------------------ */
const confirmTarget = ref(null)
const deleting = ref(false)

async function confirmDelete() {
  if (!confirmTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/appointments/${confirmTarget.value.id}`)
    confirmTarget.value = null
    await loadAppointments()
  } catch (err) {
    listError.value = parseApiError(err, 'Could not delete appointment.').message
    confirmTarget.value = null
  } finally {
    deleting.value = false
  }
}

/* ------------------------------ Payments ------------------------------ */
const paymentTarget = ref(null)

const currency = computed(() => authStore.organization?.currency || 'USD')
function money(amount) {
  const value = Number(amount ?? 0)
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value }).format(value)
  } catch {
    return `${currency.value} ${value.toFixed(2)}`
  }
}

onMounted(loadAppointments)
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Appointments</h1>
        <p class="mt-1 text-sm text-slate-500">Book and manage your day's schedule.</p>
      </div>
      <button
        v-if="canWrite"
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        New appointment
      </button>
    </div>

    <!-- Top bar: date + status filter -->
    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <div>
        <label class="mb-1 block text-xs font-medium text-slate-500">Date</label>
        <input
          v-model="selectedDate"
          type="date"
          class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
        />
      </div>
      <div>
        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
        <select
          v-model="statusFilter"
          class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
        >
          <option value="">All statuses</option>
          <option v-for="key in STATUS_KEYS" :key="key" :value="key">{{ statusLabel(key) }}</option>
        </select>
      </div>
    </div>

    <div
      v-if="listError"
      class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
    >
      {{ listError }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="rounded-2xl bg-white p-10 text-center ring-1 ring-slate-200">
      <svg class="mx-auto h-6 w-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
      <p class="mt-3 text-sm text-slate-500">Loading appointments…</p>
    </div>

    <!-- Empty -->
    <div
      v-else-if="sortedAppointments.length === 0"
      class="rounded-2xl bg-white p-10 text-center ring-1 ring-slate-200"
    >
      <p class="text-sm font-medium text-slate-900">No appointments for this day</p>
      <p class="mt-1 text-sm text-slate-500">Pick another date or create a new booking.</p>
      <button
        v-if="canWrite"
        type="button"
        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        New appointment
      </button>
    </div>

    <!-- List -->
    <div v-else class="space-y-3">
      <div
        v-for="appt in sortedAppointments"
        :key="appt.id"
        class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-base font-semibold text-slate-900">
                {{ appt.start_time }}<span v-if="appt.end_time">–{{ appt.end_time }}</span>
              </span>
              <span
                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="statusBadge(appt.status)"
              >
                {{ statusLabel(appt.status) }}
              </span>
            </div>
            <p class="mt-1 font-medium text-slate-900">
              {{ appt.customer?.name || 'Customer' }}
              <span v-if="appt.customer?.phone" class="text-sm font-normal text-slate-500">
                · {{ appt.customer.phone }}
              </span>
            </p>
            <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
              <div class="flex gap-2">
                <dt class="text-slate-400">Services</dt>
                <dd class="truncate text-slate-700">{{ (appt.services || []).map((s) => s.name).join(', ') || '—' }}</dd>
              </div>
              <div class="flex gap-2">
                <dt class="text-slate-400">Staff</dt>
                <dd class="truncate text-slate-700">{{ appt.staff?.name || '—' }}</dd>
              </div>
              <div class="flex gap-2">
                <dt class="text-slate-400">Branch</dt>
                <dd class="truncate text-slate-700">{{ appt.branch?.name || '—' }}</dd>
              </div>
              <div v-if="appt.price != null" class="flex gap-2">
                <dt class="text-slate-400">Price</dt>
                <dd class="truncate font-medium text-slate-900">{{ money(appt.price) }}</dd>
              </div>
            </dl>
            <p v-if="appt.notes" class="mt-2 text-sm text-slate-500">{{ appt.notes }}</p>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4">
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="action in QUICK_ACTIONS"
              v-show="appt.status !== action"
              :key="action"
              type="button"
              :disabled="statusUpdatingId === appt.id"
              class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
              @click="setStatus(appt, action)"
            >
              {{ statusLabel(action) }}
            </button>
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
              @click="paymentTarget = appt"
            >
              Invoice
            </button>
            <template v-if="canWrite">
            <button
              type="button"
              class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
              @click="openEdit(appt)"
            >
              Edit
            </button>
            <button
              type="button"
              class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
              @click="confirmTarget = appt"
            >
              Delete
            </button>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Create / edit form -->
    <Modal
      v-if="showForm"
      :title="editing ? 'Edit appointment' : 'New appointment'"
      size="lg"
      @close="closeForm"
    >
      <div
        v-if="formMessage"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ formMessage }}
      </div>

      <p v-if="optionsLoading" class="mb-4 text-sm text-slate-500">Loading options…</p>

      <form id="appointment-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitForm">
        <!-- Branch -->
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Branch <span class="text-rose-500">*</span></label>
          <select
            v-model="form.branch_id"
            required
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          >
            <option value="" disabled>Select a branch</option>
            <option v-for="branch in branchList" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
          <p v-if="formErrors.branch_id" class="mt-1 text-sm text-rose-600">{{ formErrors.branch_id[0] }}</p>
        </div>

        <!-- Services -->
        <div>
          <fieldset>
            <legend class="mb-1 block text-sm font-medium text-slate-700">Services <span class="text-rose-500">*</span></legend>
            <label v-for="svc in serviceList" :key="svc.id" class="flex items-center gap-2 py-1 text-sm text-slate-700">
              <input type="checkbox" :value="svc.id" v-model="form.service_ids" />
              <span>{{ serviceLabel(svc) }}</span>
            </label>
            <p v-if="form.service_ids.length" class="mt-2 text-sm text-slate-500">
              {{ form.service_ids.length }} selected · {{ formDuration }} min · {{ formTotal.toFixed(2) }}
            </p>
            <p v-if="serviceIdsErrors" class="mt-1 text-sm text-rose-600">{{ serviceIdsErrors[0] }}</p>
          </fieldset>
        </div>

        <!-- Staff -->
        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Staff <span class="text-rose-500">*</span></label>
          <select
            v-model="form.staff_id"
            required
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          >
            <option value="" disabled>Select a staff member</option>
            <option v-for="member in filteredStaff" :key="member.id" :value="member.id">{{ member.name }}</option>
          </select>
          <p v-if="noStaffMatch" class="mt-1 text-xs text-slate-400">
            No staff can perform every selected service — showing everyone.
          </p>
          <p v-if="formErrors.staff_id" class="mt-1 text-sm text-rose-600">{{ formErrors.staff_id[0] }}</p>
        </div>

        <!-- Customer -->
        <div class="sm:col-span-2">
          <div class="mb-1 flex items-center justify-between">
            <label class="block text-sm font-medium text-slate-700">Customer <span class="text-rose-500">*</span></label>
            <div v-if="!editing" class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs">
              <button
                type="button"
                class="rounded-md px-2.5 py-1 font-medium transition"
                :class="form.customerMode === 'existing' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                @click="form.customerMode = 'existing'"
              >
                Existing
              </button>
              <button
                type="button"
                class="rounded-md px-2.5 py-1 font-medium transition"
                :class="form.customerMode === 'new' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                @click="form.customerMode = 'new'"
              >
                New
              </button>
            </div>
          </div>

          <div v-if="editing || form.customerMode === 'existing'">
            <select
              v-model="form.customer_id"
              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            >
              <option value="" disabled>Select a customer</option>
              <option v-for="c in customerList" :key="c.id" :value="c.id">
                {{ c.name }}<template v-if="c.phone"> · {{ c.phone }}</template>
              </option>
            </select>
            <p v-if="formErrors.customer_id" class="mt-1 text-sm text-rose-600">{{ formErrors.customer_id[0] }}</p>
          </div>

          <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
              <input
                v-model="form.new_customer.name"
                type="text"
                placeholder="Name *"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <p v-if="formErrors['new_customer.name']" class="mt-1 text-sm text-rose-600">{{ formErrors['new_customer.name'][0] }}</p>
            </div>
            <div>
              <input
                v-model="form.new_customer.phone"
                type="text"
                placeholder="Phone"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <p v-if="formErrors['new_customer.phone']" class="mt-1 text-sm text-rose-600">{{ formErrors['new_customer.phone'][0] }}</p>
            </div>
            <div>
              <input
                v-model="form.new_customer.email"
                type="email"
                placeholder="Email"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <p v-if="formErrors['new_customer.email']" class="mt-1 text-sm text-rose-600">{{ formErrors['new_customer.email'][0] }}</p>
            </div>
          </div>
        </div>

        <!-- Date + time -->
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Date <span class="text-rose-500">*</span></label>
          <input
            v-model="form.booking_date"
            type="date"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="formErrors.booking_date" class="mt-1 text-sm text-rose-600">{{ formErrors.booking_date[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Start time <span class="text-rose-500">*</span></label>
          <input
            v-model="form.start_time"
            type="time"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="formErrors.start_time" class="mt-1 text-sm text-rose-600">{{ formErrors.start_time[0] }}</p>
        </div>

        <!-- Status -->
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
          <select
            v-model="form.status"
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          >
            <option v-for="key in STATUS_KEYS" :key="key" :value="key">{{ statusLabel(key) }}</option>
          </select>
          <p v-if="formErrors.status" class="mt-1 text-sm text-rose-600">{{ formErrors.status[0] }}</p>
        </div>

        <!-- Notes -->
        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Notes</label>
          <textarea
            v-model="form.notes"
            rows="2"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="Optional details"
          ></textarea>
          <p v-if="formErrors.notes" class="mt-1 text-sm text-rose-600">{{ formErrors.notes[0] }}</p>
        </div>
      </form>

      <template #footer>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
          @click="closeForm"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="appointment-form"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Create appointment' }}
        </button>
      </template>
    </Modal>

    <!-- Delete confirm -->
    <ConfirmDialog
      v-if="confirmTarget"
      title="Delete appointment"
      :message="`Delete the ${confirmTarget.start_time} booking for ${confirmTarget.customer?.name || 'this customer'}? This cannot be undone.`"
      :loading="deleting"
      @confirm="confirmDelete"
      @cancel="confirmTarget = null"
    />

    <!-- Invoice + payments -->
    <PaymentModal
      v-if="paymentTarget"
      :appointment="paymentTarget"
      @changed="loadAppointments"
      @close="paymentTarget = null"
    />
  </div>
</template>
