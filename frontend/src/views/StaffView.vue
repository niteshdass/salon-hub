<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { isPlanLimit, parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const authStore = useAuthStore()

// ISO weekday: 1=Mon .. 7=Sun (matches SlotGenerator + working_days_json).
const WEEKDAYS = [
  { value: 1, label: 'Mon' },
  { value: 2, label: 'Tue' },
  { value: 3, label: 'Wed' },
  { value: 4, label: 'Thu' },
  { value: 5, label: 'Fri' },
  { value: 6, label: 'Sat' },
  { value: 7, label: 'Sun' },
]

const staff = ref([])
const loading = ref(false)
const listError = ref('')

const serviceOptions = ref([])

const planLimitMessage = ref('')

const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const formMessage = ref('')
const formErrors = ref({})

const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  designation: '',
  bio: '',
  service_ids: [],
  // Empty working_days => available every day; blank hours => 09:00–18:00
  // (SlotGenerator applies those same defaults server-side).
  working_days: [],
  working_hours_start: '',
  working_hours_end: '',
})

// Owner + manager maintain the team; staff only read it.
const canWrite = computed(() => authStore.canManageOperations)

const confirmTarget = ref(null)
const deleting = ref(false)

const isFreePlan = computed(() => {
  const plan = authStore.organization?.subscription_plan
  return !plan || String(plan).toLowerCase() === 'free'
})

// Free plan tops out at 10 staff members.
const staffLimitReached = computed(
  () => isFreePlan.value && staff.value.length >= 10,
)

async function loadStaff() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/staff')
    staff.value = data.data || []
  } catch (err) {
    listError.value = parseApiError(err, 'Could not load staff.').message
  } finally {
    loading.value = false
  }
}

async function loadServiceOptions() {
  try {
    const { data } = await api.get('/services')
    serviceOptions.value = data.data || []
  } catch {
    serviceOptions.value = []
  }
}

function resetForm() {
  Object.assign(form, {
    name: '',
    email: '',
    phone: '',
    password: '',
    designation: '',
    bio: '',
    service_ids: [],
    working_days: [],
    working_hours_start: '',
    working_hours_end: '',
  })
  formErrors.value = {}
  formMessage.value = ''
}

function openCreate() {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(member) {
  editing.value = member
  resetForm()
  Object.assign(form, {
    name: member.name || '',
    email: member.email || '',
    phone: member.phone || '',
    password: '',
    designation: member.designation || '',
    bio: member.bio || '',
    service_ids: (member.services || []).map((s) => s.id),
    working_days: Array.isArray(member.working_days_json) ? [...member.working_days_json] : [],
    working_hours_start: member.working_hours_json?.start || '',
    working_hours_end: member.working_hours_json?.end || '',
  })
  showForm.value = true
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
    name: form.name,
    email: form.email,
    phone: form.phone || null,
    designation: form.designation || null,
    bio: form.bio || null,
    service_ids: form.service_ids,
    // Empty selection => null (available every day, per SlotGenerator default).
    working_days_json: form.working_days.length ? [...form.working_days].sort((a, b) => a - b) : null,
    // Send hours only when both ends are set; otherwise null => default 09:00–18:00.
    working_hours_json:
      form.working_hours_start && form.working_hours_end
        ? { start: form.working_hours_start, end: form.working_hours_end }
        : null,
  }
  // Only send a password when one was typed (blank => backend auto-generates).
  if (form.password) payload.password = form.password

  try {
    if (editing.value) {
      await api.put(`/staff/${editing.value.id}`, payload)
    } else {
      await api.post('/staff', payload)
    }
    closeForm()
    await loadStaff()
  } catch (err) {
    const parsed = parseApiError(err)
    if (isPlanLimit(err)) {
      planLimitMessage.value = parsed.message
      closeForm()
    } else {
      formErrors.value = parsed.errors
      formMessage.value = parsed.message
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete() {
  if (!confirmTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/staff/${confirmTarget.value.id}`)
    confirmTarget.value = null
    await loadStaff()
  } catch (err) {
    listError.value = parseApiError(err, 'Could not delete staff member.').message
    confirmTarget.value = null
  } finally {
    deleting.value = false
  }
}

// ── Time off ──────────────────────────────────────────────────────────────
// One member's time-off is managed in its own modal. Blocks book online
// during the range (SlotGenerator drops those slots).
const timeOffTarget = ref(null)
const timeOffList = ref([])
const timeOffLoading = ref(false)
const timeOffError = ref('')
const timeOffSaving = ref(false)
const timeOffFormErrors = ref({})
const timeOffDeletingId = ref(null)

const timeOffForm = reactive({
  start_at: '',
  end_at: '',
  reason: '',
})

function openTimeOff(member) {
  timeOffTarget.value = member
  timeOffList.value = []
  timeOffError.value = ''
  timeOffFormErrors.value = {}
  Object.assign(timeOffForm, { start_at: '', end_at: '', reason: '' })
  loadTimeOff()
}

function closeTimeOff() {
  timeOffTarget.value = null
}

async function loadTimeOff() {
  if (!timeOffTarget.value) return
  timeOffLoading.value = true
  timeOffError.value = ''
  try {
    const { data } = await api.get(`/staff/${timeOffTarget.value.id}/time-off`)
    timeOffList.value = data.data || []
  } catch (err) {
    timeOffError.value = parseApiError(err, 'Could not load time off.').message
  } finally {
    timeOffLoading.value = false
  }
}

async function submitTimeOff() {
  if (!timeOffTarget.value) return
  timeOffSaving.value = true
  timeOffFormErrors.value = {}
  try {
    await api.post(`/staff/${timeOffTarget.value.id}/time-off`, {
      start_at: timeOffForm.start_at,
      end_at: timeOffForm.end_at,
      reason: timeOffForm.reason || null,
    })
    Object.assign(timeOffForm, { start_at: '', end_at: '', reason: '' })
    await loadTimeOff()
  } catch (err) {
    timeOffFormErrors.value = parseApiError(err).errors
  } finally {
    timeOffSaving.value = false
  }
}

async function deleteTimeOff(id) {
  if (!timeOffTarget.value) return
  timeOffDeletingId.value = id
  try {
    await api.delete(`/staff/${timeOffTarget.value.id}/time-off/${id}`)
    await loadTimeOff()
  } catch (err) {
    timeOffError.value = parseApiError(err, 'Could not delete time off.').message
  } finally {
    timeOffDeletingId.value = null
  }
}

// "3 Aug 2026, 12:00" from an ISO datetime.
function formatDateTime(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function toggleService(id) {
  const idx = form.service_ids.indexOf(id)
  if (idx === -1) form.service_ids.push(id)
  else form.service_ids.splice(idx, 1)
}

function toggleDay(value) {
  const idx = form.working_days.indexOf(value)
  if (idx === -1) form.working_days.push(value)
  else form.working_days.splice(idx, 1)
}

// Short human summary of a member's schedule for the card.
function scheduleSummary(member) {
  const days = member.working_days_json
  const hours = member.working_hours_json
  const dayLabel =
    Array.isArray(days) && days.length
      ? [...days].sort((a, b) => a - b).map((d) => WEEKDAYS.find((w) => w.value === d)?.label).join(', ')
      : 'Every day'
  const hourLabel = hours?.start && hours?.end ? `${hours.start}–${hours.end}` : '09:00–18:00'
  return `${dayLabel} · ${hourLabel}`
}

onMounted(() => {
  loadStaff()
  loadServiceOptions()
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Staff</h1>
        <p class="mt-1 text-sm text-slate-500">Manage your team and their services.</p>
      </div>
      <button
        v-if="canWrite && !staffLimitReached"
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Add staff
      </button>
      <p v-else-if="canWrite" class="text-xs text-slate-500">
        Your free plan allows only 10 staff.
      </p>
    </div>

    <div
      v-if="planLimitMessage"
      class="mb-5 flex items-start justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
    >
      <span>{{ planLimitMessage }}</span>
      <button type="button" class="font-medium text-amber-700 hover:text-amber-900" @click="planLimitMessage = ''">
        Dismiss
      </button>
    </div>

    <div
      v-if="listError"
      class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
    >
      {{ listError }}
    </div>

    <div v-if="loading" class="rounded-2xl bg-white p-10 text-center text-sm text-slate-500 ring-1 ring-slate-200">
      Loading staff…
    </div>

    <div
      v-else-if="staff.length === 0"
      class="rounded-2xl bg-white p-10 text-center ring-1 ring-slate-200"
    >
      <p class="text-sm font-medium text-slate-900">No staff yet</p>
      <p class="mt-1 text-sm text-slate-500">Add your first team member to get started.</p>
      <button
        v-if="canWrite"
        type="button"
        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        Add staff
      </button>
    </div>

    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <div
        v-for="member in staff"
        :key="member.id"
        class="flex flex-col rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
      >
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-sm font-semibold text-indigo-700">
            {{ (member.name || '?').charAt(0).toUpperCase() }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate font-semibold text-slate-900">{{ member.name }}</p>
            <p v-if="member.designation" class="truncate text-xs text-slate-500">{{ member.designation }}</p>
          </div>
        </div>

        <dl class="mt-4 space-y-1 text-sm">
          <div class="flex gap-2">
            <dt class="w-14 shrink-0 text-slate-400">Email</dt>
            <dd class="truncate text-slate-700">{{ member.email || '—' }}</dd>
          </div>
          <div class="flex gap-2">
            <dt class="w-14 shrink-0 text-slate-400">Phone</dt>
            <dd class="truncate text-slate-700">{{ member.phone || '—' }}</dd>
          </div>
          <div class="flex gap-2">
            <dt class="w-14 shrink-0 text-slate-400">Hours</dt>
            <dd class="truncate text-slate-700">{{ scheduleSummary(member) }}</dd>
          </div>
        </dl>

        <div class="mt-4 flex flex-wrap gap-1.5">
          <span
            v-for="svc in member.services || []"
            :key="svc.id"
            class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600"
          >
            {{ svc.name }}
          </span>
          <span v-if="!(member.services && member.services.length)" class="text-xs text-slate-400">
            No services assigned
          </span>
        </div>

        <div v-if="canWrite" class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button
            type="button"
            class="mr-auto rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            @click="openTimeOff(member)"
          >
            Time off
          </button>
          <button
            type="button"
            class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            @click="openEdit(member)"
          >
            Edit
          </button>
          <button
            type="button"
            class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
            @click="confirmTarget = member"
          >
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Create / edit form -->
    <Modal
      v-if="showForm"
      :title="editing ? 'Edit staff' : 'Add staff'"
      size="lg"
      @close="closeForm"
    >
      <div
        v-if="formMessage"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ formMessage }}
      </div>

      <form id="staff-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitForm">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Name <span class="text-rose-500">*</span></label>
          <input
            v-model="form.name"
            type="text"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="Jane Doe"
          />
          <p v-if="formErrors.name" class="mt-1 text-sm text-rose-600">{{ formErrors.name[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Email <span class="text-rose-500">*</span></label>
          <input
            v-model="form.email"
            type="email"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="jane@example.com"
          />
          <p v-if="formErrors.email" class="mt-1 text-sm text-rose-600">{{ formErrors.email[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
          <input v-model="form.phone" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.phone" class="mt-1 text-sm text-rose-600">{{ formErrors.phone[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Designation</label>
          <input v-model="form.designation" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Stylist" />
          <p v-if="formErrors.designation" class="mt-1 text-sm text-rose-600">{{ formErrors.designation[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Password</label>
          <input
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="••••••••"
          />
          <p class="mt-1 text-xs text-slate-400">Leave blank to auto-generate a password.</p>
          <p v-if="formErrors.password" class="mt-1 text-sm text-rose-600">{{ formErrors.password[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Bio</label>
          <textarea
            v-model="form.bio"
            rows="2"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          ></textarea>
          <p v-if="formErrors.bio" class="mt-1 text-sm text-rose-600">{{ formErrors.bio[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="mb-2 block text-sm font-medium text-slate-700">Working days</label>
          <div class="flex flex-wrap gap-2">
            <label
              v-for="day in WEEKDAYS"
              :key="day.value"
              class="cursor-pointer select-none rounded-lg border px-3 py-1.5 text-sm font-medium transition"
              :class="form.working_days.includes(day.value)
                ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
            >
              <input
                type="checkbox"
                class="sr-only"
                :checked="form.working_days.includes(day.value)"
                @change="toggleDay(day.value)"
              />
              {{ day.label }}
            </label>
          </div>
          <p class="mt-1 text-xs text-slate-400">Leave all unchecked to make this member available every day.</p>
          <p v-if="formErrors['working_days_json'] || formErrors['working_days_json.0']" class="mt-1 text-sm text-rose-600">
            {{ (formErrors['working_days_json'] || formErrors['working_days_json.0'])[0] }}
          </p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Starts at</label>
          <input
            v-model="form.working_hours_start"
            type="time"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="formErrors['working_hours_json.start']" class="mt-1 text-sm text-rose-600">{{ formErrors['working_hours_json.start'][0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Ends at</label>
          <input
            v-model="form.working_hours_end"
            type="time"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p class="mt-1 text-xs text-slate-400">Leave blank for the default 09:00–18:00.</p>
          <p v-if="formErrors['working_hours_json.end']" class="mt-1 text-sm text-rose-600">{{ formErrors['working_hours_json.end'][0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="mb-2 block text-sm font-medium text-slate-700">Services</label>
          <p v-if="serviceOptions.length === 0" class="text-sm text-slate-400">
            No services available yet.
          </p>
          <div v-else class="grid max-h-44 grid-cols-1 gap-2 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
            <label
              v-for="svc in serviceOptions"
              :key="svc.id"
              class="flex cursor-pointer items-center gap-2 text-sm text-slate-700"
            >
              <input
                type="checkbox"
                :value="svc.id"
                :checked="form.service_ids.includes(svc.id)"
                class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-300"
                @change="toggleService(svc.id)"
              />
              {{ svc.name }}
            </label>
          </div>
          <p v-if="formErrors.service_ids" class="mt-1 text-sm text-rose-600">{{ formErrors.service_ids[0] }}</p>
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
          form="staff-form"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Create staff' }}
        </button>
      </template>
    </Modal>

    <!-- Time off -->
    <Modal
      v-if="timeOffTarget"
      :title="`Time off — ${timeOffTarget.name}`"
      size="lg"
      @close="closeTimeOff"
    >
      <div
        v-if="timeOffError"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ timeOffError }}
      </div>

      <!-- Add form -->
      <form class="grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="submitTimeOff">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Starts <span class="text-rose-500">*</span></label>
          <input
            v-model="timeOffForm.start_at"
            type="datetime-local"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="timeOffFormErrors.start_at" class="mt-1 text-sm text-rose-600">{{ timeOffFormErrors.start_at[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Ends <span class="text-rose-500">*</span></label>
          <input
            v-model="timeOffForm.end_at"
            type="datetime-local"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="timeOffFormErrors.end_at" class="mt-1 text-sm text-rose-600">{{ timeOffFormErrors.end_at[0] }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Reason</label>
          <input
            v-model="timeOffForm.reason"
            type="text"
            maxlength="255"
            placeholder="Vacation, sick leave…"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="timeOffFormErrors.reason" class="mt-1 text-sm text-rose-600">{{ timeOffFormErrors.reason[0] }}</p>
        </div>
        <div class="sm:col-span-2">
          <button
            type="submit"
            :disabled="timeOffSaving"
            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ timeOffSaving ? 'Adding…' : 'Add time off' }}
          </button>
        </div>
      </form>

      <!-- List -->
      <div class="mt-6 border-t border-slate-100 pt-4">
        <p v-if="timeOffLoading" class="text-sm text-slate-500">Loading…</p>
        <p v-else-if="timeOffList.length === 0" class="text-sm text-slate-400">
          No time off scheduled.
        </p>
        <ul v-else class="space-y-2">
          <li
            v-for="entry in timeOffList"
            :key="entry.id"
            class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2.5"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-slate-900">
                {{ formatDateTime(entry.start_at) }} → {{ formatDateTime(entry.end_at) }}
              </p>
              <p v-if="entry.reason" class="truncate text-xs text-slate-500">{{ entry.reason }}</p>
            </div>
            <button
              type="button"
              :disabled="timeOffDeletingId === entry.id"
              class="shrink-0 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-medium text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"
              @click="deleteTimeOff(entry.id)"
            >
              {{ timeOffDeletingId === entry.id ? 'Removing…' : 'Remove' }}
            </button>
          </li>
        </ul>
      </div>

      <template #footer>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
          @click="closeTimeOff"
        >
          Close
        </button>
      </template>
    </Modal>

    <!-- Delete confirm -->
    <ConfirmDialog
      v-if="confirmTarget"
      title="Delete staff"
      :message="`Delete “${confirmTarget.name}”? This cannot be undone.`"
      :loading="deleting"
      @confirm="confirmDelete"
      @cancel="confirmTarget = null"
    />
  </div>
</template>
