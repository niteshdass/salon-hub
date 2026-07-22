<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { isPlanLimit, parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const authStore = useAuthStore()

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
})

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

onMounted(loadBranches)
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Branches</h1>
        <p class="mt-1 text-sm text-slate-500">Manage your salon locations.</p>
      </div>
      <button
        v-if="!branchLimitReached"
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Add branch
      </button>
      <p v-else class="text-xs text-slate-500">
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
        v-if="!branchLimitReached"
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
              <div class="flex justify-end gap-2">
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
