<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { isPlanLimit, parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const authStore = useAuthStore()

// Short keys match the opening_hours_json the API stores + SlotGenerator reads.
const DAYS = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
]

function defaultHours() {
  return Object.fromEntries(
    DAYS.map((d) => [d.key, { enabled: !['sat', 'sun'].includes(d.key), open: '09:00', close: '18:00' }]),
  )
}

const branches = ref([])
const loading = ref(false)
const listError = ref('')

// Banner shown when the backend rejects a create due to the free-plan limit.
const planLimitMessage = ref('')

const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const formMessage = ref('')
const formErrors = ref({})

const form = reactive({
  name: '',
  phone: '',
  email: '',
  address: '',
  city: '',
  country: '',
  latitude: '',
  longitude: '',
  // When off, the branch imposes no hours (bookable whenever staff are).
  useHours: false,
  hours: defaultHours(),
})

// Branches are org-level configuration: the API only lets an owner write
// them, so hide the controls for everyone else.
const canWrite = computed(() => authStore.isOwner)

const confirmTarget = ref(null)
const deleting = ref(false)

const isFreePlan = computed(() => {
  const plan = authStore.organization?.subscription_plan
  return !plan || String(plan).toLowerCase() === 'free'
})

// Nice touch: on the free plan a single branch is the ceiling, so hide "Add".
const branchLimitReached = computed(
  () => isFreePlan.value && branches.value.length >= 1,
)

async function loadBranches() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/branches')
    branches.value = data.data || []
  } catch (err) {
    listError.value = parseApiError(err, 'Could not load branches.').message
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, {
    name: '',
    phone: '',
    email: '',
    address: '',
    city: '',
    country: '',
    latitude: '',
    longitude: '',
    useHours: false,
    hours: defaultHours(),
  })
  formErrors.value = {}
  formMessage.value = ''
}

function openCreate() {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(branch) {
  editing.value = branch
  resetForm()
  Object.assign(form, {
    name: branch.name || '',
    phone: branch.phone || '',
    email: branch.email || '',
    address: branch.address || '',
    city: branch.city || '',
    country: branch.country || '',
    latitude: branch.latitude ?? '',
    longitude: branch.longitude ?? '',
  })

  // Hydrate the hours grid: a stored map means custom hours; each day is an
  // [open, close] pair when open, or null/absent when closed.
  const stored = branch.opening_hours_json
  if (stored && typeof stored === 'object' && Object.keys(stored).length) {
    form.useHours = true
    for (const { key } of DAYS) {
      const pair = stored[key]
      if (Array.isArray(pair) && pair.length === 2) {
        form.hours[key] = { enabled: true, open: pair[0], close: pair[1] }
      } else {
        form.hours[key] = { ...form.hours[key], enabled: false }
      }
    }
  }

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
    phone: form.phone || null,
    email: form.email || null,
    address: form.address || null,
    city: form.city || null,
    country: form.country || null,
    latitude: form.latitude === '' ? null : Number(form.latitude),
    longitude: form.longitude === '' ? null : Number(form.longitude),
    // Off => null (unrestricted). On => per-day [open, close] or null (closed).
    opening_hours_json: form.useHours
      ? Object.fromEntries(
          DAYS.map(({ key }) => {
            const d = form.hours[key]
            return [key, d.enabled ? [d.open, d.close] : null]
          }),
        )
      : null,
  }

  try {
    if (editing.value) {
      await api.put(`/branches/${editing.value.id}`, payload)
    } else {
      await api.post('/branches', payload)
    }
    closeForm()
    await loadBranches()
  } catch (err) {
    const parsed = parseApiError(err)
    if (isPlanLimit(err)) {
      // Surface plan limits on the page banner, then close the form.
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
    await api.delete(`/branches/${confirmTarget.value.id}`)
    confirmTarget.value = null
    await loadBranches()
  } catch (err) {
    listError.value = parseApiError(err, 'Could not delete branch.').message
    confirmTarget.value = null
  } finally {
    deleting.value = false
  }
}

// ── Closures ────────────────────────────────────────────────────────────────
// Whole-day closures for one branch or the whole salon (null branch). Blocks
// online booking for the covered dates (SlotGenerator returns no slots).
const closures = ref([])
const closuresLoading = ref(false)
const closuresError = ref('')

const showClosureForm = ref(false)
const closureSaving = ref(false)
const closureFormErrors = ref({})

const closureForm = reactive({
  branch_id: '', // '' => all branches (org-wide)
  start_date: '',
  end_date: '',
  reason: '',
})

const closureConfirmTarget = ref(null)
const closureDeleting = ref(false)

const branchName = computed(() => {
  const map = {}
  for (const b of branches.value) map[b.id] = b.name
  return (id) => (id == null ? 'All branches' : map[id] || `Branch #${id}`)
})

async function loadClosures() {
  closuresLoading.value = true
  closuresError.value = ''
  try {
    const { data } = await api.get('/branch-closures')
    closures.value = data.data || []
  } catch (err) {
    closuresError.value = parseApiError(err, 'Could not load closures.').message
  } finally {
    closuresLoading.value = false
  }
}

function openClosureForm() {
  Object.assign(closureForm, { branch_id: '', start_date: '', end_date: '', reason: '' })
  closureFormErrors.value = {}
  showClosureForm.value = true
}

function closeClosureForm() {
  showClosureForm.value = false
}

async function submitClosure() {
  closureSaving.value = true
  closureFormErrors.value = {}
  try {
    await api.post('/branch-closures', {
      branch_id: closureForm.branch_id === '' ? null : closureForm.branch_id,
      start_date: closureForm.start_date,
      end_date: closureForm.end_date,
      reason: closureForm.reason || null,
    })
    closeClosureForm()
    await loadClosures()
  } catch (err) {
    closureFormErrors.value = parseApiError(err).errors
  } finally {
    closureSaving.value = false
  }
}

async function confirmDeleteClosure() {
  if (!closureConfirmTarget.value) return
  closureDeleting.value = true
  try {
    await api.delete(`/branch-closures/${closureConfirmTarget.value.id}`)
    closureConfirmTarget.value = null
    await loadClosures()
  } catch (err) {
    closuresError.value = parseApiError(err, 'Could not delete closure.').message
    closureConfirmTarget.value = null
  } finally {
    closureDeleting.value = false
  }
}

// "25 Dec 2026" from a YYYY-MM-DD string, and a range collapsed when equal.
function formatDate(value) {
  if (!value) return '—'
  const d = new Date(`${value}T00:00:00`)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

function closureRange(entry) {
  return entry.start_date === entry.end_date
    ? formatDate(entry.start_date)
    : `${formatDate(entry.start_date)} → ${formatDate(entry.end_date)}`
}

onMounted(() => {
  loadBranches()
  loadClosures()
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Branches</h1>
        <p class="mt-1 text-sm text-slate-500">Manage your salon locations.</p>
      </div>
      <button
        v-if="canWrite && !branchLimitReached"
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Add branch
      </button>
      <p v-else-if="canWrite" class="text-xs text-slate-500">
        Your free plan allows only 1 branch.
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

    <!-- Loading -->
    <div v-if="loading" class="rounded-2xl bg-white p-10 text-center text-sm text-slate-500 ring-1 ring-slate-200">
      Loading branches…
    </div>

    <!-- Empty -->
    <div
      v-else-if="branches.length === 0"
      class="rounded-2xl bg-white p-10 text-center ring-1 ring-slate-200"
    >
      <p class="text-sm font-medium text-slate-900">No branches yet</p>
      <p class="mt-1 text-sm text-slate-500">Add your first location to get started.</p>
      <button
        v-if="canWrite && !branchLimitReached"
        type="button"
        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        Add branch
      </button>
    </div>

    <!-- List -->
    <div v-else class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
            <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:table-cell">Phone</th>
            <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 md:table-cell">City</th>
            <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 lg:table-cell">Email</th>
            <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="branch in branches" :key="branch.id" class="hover:bg-slate-50">
            <td class="px-5 py-3.5">
              <p class="font-medium text-slate-900">{{ branch.name }}</p>
              <p class="text-xs text-slate-500 sm:hidden">{{ branch.city || branch.phone || '—' }}</p>
            </td>
            <td class="hidden px-5 py-3.5 text-sm text-slate-600 sm:table-cell">{{ branch.phone || '—' }}</td>
            <td class="hidden px-5 py-3.5 text-sm text-slate-600 md:table-cell">{{ branch.city || '—' }}</td>
            <td class="hidden px-5 py-3.5 text-sm text-slate-600 lg:table-cell">{{ branch.email || '—' }}</td>
            <td class="px-5 py-3.5 text-right">
              <div v-if="canWrite" class="flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                  @click="openEdit(branch)"
                >
                  Edit
                </button>
                <button
                  type="button"
                  class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
                  @click="confirmTarget = branch"
                >
                  Delete
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Closures -->
    <div class="mt-10">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-bold text-slate-900">Closures &amp; holidays</h2>
          <p class="mt-1 text-sm text-slate-500">Block online booking for whole days, per branch or salon-wide.</p>
        </div>
        <button
          v-if="canWrite"
          type="button"
          class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
          @click="openClosureForm"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          Add closure
        </button>
      </div>

      <div
        v-if="closuresError"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ closuresError }}
      </div>

      <div v-if="closuresLoading" class="rounded-2xl bg-white p-8 text-center text-sm text-slate-500 ring-1 ring-slate-200">
        Loading closures…
      </div>
      <div
        v-else-if="closures.length === 0"
        class="rounded-2xl bg-white p-8 text-center ring-1 ring-slate-200"
      >
        <p class="text-sm font-medium text-slate-900">No closures scheduled</p>
        <p class="mt-1 text-sm text-slate-500">Add holidays or one-off closed days.</p>
      </div>
      <div v-else class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Dates</th>
              <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Branch</th>
              <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:table-cell">Reason</th>
              <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="entry in closures" :key="entry.id" class="hover:bg-slate-50">
              <td class="px-5 py-3.5 text-sm font-medium text-slate-900">{{ closureRange(entry) }}</td>
              <td class="px-5 py-3.5 text-sm text-slate-600">
                <span
                  v-if="entry.branch_id == null"
                  class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700"
                >
                  All branches
                </span>
                <span v-else>{{ branchName(entry.branch_id) }}</span>
              </td>
              <td class="hidden px-5 py-3.5 text-sm text-slate-600 sm:table-cell">{{ entry.reason || '—' }}</td>
              <td class="px-5 py-3.5 text-right">
                <button
                  v-if="canWrite"
                  type="button"
                  class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
                  @click="closureConfirmTarget = entry"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add closure form -->
    <Modal
      v-if="showClosureForm"
      title="Add closure"
      size="md"
      @close="closeClosureForm"
    >
      <form id="closure-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitClosure">
        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Branch</label>
          <select
            v-model="closureForm.branch_id"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          >
            <option value="">All branches (salon-wide)</option>
            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
          <p v-if="closureFormErrors.branch_id" class="mt-1 text-sm text-rose-600">{{ closureFormErrors.branch_id[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">From <span class="text-rose-500">*</span></label>
          <input
            v-model="closureForm.start_date"
            type="date"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="closureFormErrors.start_date" class="mt-1 text-sm text-rose-600">{{ closureFormErrors.start_date[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">To <span class="text-rose-500">*</span></label>
          <input
            v-model="closureForm.end_date"
            type="date"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p class="mt-1 text-xs text-slate-400">Same day for a single-day closure.</p>
          <p v-if="closureFormErrors.end_date" class="mt-1 text-sm text-rose-600">{{ closureFormErrors.end_date[0] }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Reason</label>
          <input
            v-model="closureForm.reason"
            type="text"
            maxlength="255"
            placeholder="Public holiday, renovation…"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
          <p v-if="closureFormErrors.reason" class="mt-1 text-sm text-rose-600">{{ closureFormErrors.reason[0] }}</p>
        </div>
      </form>

      <template #footer>
        <button
          type="button"
          class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
          @click="closeClosureForm"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="closure-form"
          :disabled="closureSaving"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ closureSaving ? 'Saving…' : 'Add closure' }}
        </button>
      </template>
    </Modal>

    <!-- Closure delete confirm -->
    <ConfirmDialog
      v-if="closureConfirmTarget"
      title="Delete closure"
      :message="`Delete the closure on ${closureRange(closureConfirmTarget)}? This cannot be undone.`"
      :loading="closureDeleting"
      @confirm="confirmDeleteClosure"
      @cancel="closureConfirmTarget = null"
    />

    <!-- Create / edit form -->
    <Modal
      v-if="showForm"
      :title="editing ? 'Edit branch' : 'Add branch'"
      size="lg"
      @close="closeForm"
    >
      <div
        v-if="formMessage"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ formMessage }}
      </div>

      <form id="branch-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitForm">
        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Name <span class="text-rose-500">*</span></label>
          <input
            v-model="form.name"
            type="text"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="Downtown branch"
          />
          <p v-if="formErrors.name" class="mt-1 text-sm text-rose-600">{{ formErrors.name[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
          <input v-model="form.phone" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.phone" class="mt-1 text-sm text-rose-600">{{ formErrors.phone[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
          <input v-model="form.email" type="email" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.email" class="mt-1 text-sm text-rose-600">{{ formErrors.email[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Address</label>
          <input v-model="form.address" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.address" class="mt-1 text-sm text-rose-600">{{ formErrors.address[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">City</label>
          <input v-model="form.city" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.city" class="mt-1 text-sm text-rose-600">{{ formErrors.city[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Country</label>
          <input v-model="form.country" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.country" class="mt-1 text-sm text-rose-600">{{ formErrors.country[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Latitude</label>
          <input v-model="form.latitude" type="number" step="any" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.latitude" class="mt-1 text-sm text-rose-600">{{ formErrors.latitude[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Longitude</label>
          <input v-model="form.longitude" type="number" step="any" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
          <p v-if="formErrors.longitude" class="mt-1 text-sm text-rose-600">{{ formErrors.longitude[0] }}</p>
        </div>

        <div class="sm:col-span-2 border-t border-slate-100 pt-4">
          <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="form.useHours" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-300" />
            Set opening hours
          </label>
          <p class="mt-1 text-xs text-slate-400">
            When off, this branch is bookable whenever staff are available. When on, online slots are limited to the hours below.
          </p>

          <div v-if="form.useHours" class="mt-3 space-y-2">
            <div
              v-for="day in DAYS"
              :key="day.key"
              class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 px-3 py-2"
            >
              <label class="flex w-32 shrink-0 cursor-pointer items-center gap-2 text-sm text-slate-700">
                <input
                  v-model="form.hours[day.key].enabled"
                  type="checkbox"
                  class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-300"
                />
                {{ day.label }}
              </label>
              <template v-if="form.hours[day.key].enabled">
                <input
                  v-model="form.hours[day.key].open"
                  type="time"
                  class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                />
                <span class="text-sm text-slate-400">to</span>
                <input
                  v-model="form.hours[day.key].close"
                  type="time"
                  class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                />
              </template>
              <span v-else class="text-sm text-slate-400">Closed</span>
            </div>
          </div>
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
          form="branch-form"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Create branch' }}
        </button>
      </template>
    </Modal>

    <!-- Delete confirm -->
    <ConfirmDialog
      v-if="confirmTarget"
      title="Delete branch"
      :message="`Delete “${confirmTarget.name}”? This cannot be undone.`"
      :loading="deleting"
      @confirm="confirmDelete"
      @cancel="confirmTarget = null"
    />
  </div>
</template>
