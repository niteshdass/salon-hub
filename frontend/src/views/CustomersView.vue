<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

// Staff may read the customer book but not edit it.
const authStore = useAuthStore()
const canWrite = computed(() => authStore.canManageOperations)

const customers = ref([])
const loading = ref(false)
const listError = ref('')

const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const formMessage = ref('')
const formErrors = ref({})

const form = reactive({
  name: '',
  phone: '',
  email: '',
  notes: '',
})

const confirmTarget = ref(null)
const deleting = ref(false)

async function loadCustomers() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/customers')
    customers.value = data.data || []
  } catch (err) {
    listError.value = parseApiError(err, 'Could not load customers.').message
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, {
    name: '',
    phone: '',
    email: '',
    notes: '',
  })
  formErrors.value = {}
  formMessage.value = ''
}

function openCreate() {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(customer) {
  editing.value = customer
  resetForm()
  Object.assign(form, {
    name: customer.name || '',
    phone: customer.phone || '',
    email: customer.email || '',
    notes: customer.notes || '',
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
    notes: form.notes || null,
  }

  try {
    if (editing.value) {
      await api.put(`/customers/${editing.value.id}`, payload)
    } else {
      await api.post('/customers', payload)
    }
    closeForm()
    await loadCustomers()
  } catch (err) {
    const parsed = parseApiError(err)
    formErrors.value = parsed.errors
    formMessage.value = parsed.message
  } finally {
    saving.value = false
  }
}

async function confirmDelete() {
  if (!confirmTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/customers/${confirmTarget.value.id}`)
    confirmTarget.value = null
    await loadCustomers()
  } catch (err) {
    listError.value = parseApiError(err, 'Could not delete customer.').message
    confirmTarget.value = null
  } finally {
    deleting.value = false
  }
}

onMounted(loadCustomers)
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Customers</h1>
        <p class="mt-1 text-sm text-slate-500">Manage the people who book with you.</p>
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
        Add customer
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
      Loading customers…
    </div>

    <!-- Empty -->
    <div
      v-else-if="customers.length === 0"
      class="rounded-2xl bg-white p-10 text-center ring-1 ring-slate-200"
    >
      <p class="text-sm font-medium text-slate-900">No customers yet</p>
      <p class="mt-1 text-sm text-slate-500">Add your first customer to get started.</p>
      <button
        v-if="canWrite"
        type="button"
        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
        @click="openCreate"
      >
        Add customer
      </button>
    </div>

    <!-- List -->
    <div v-else class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
              <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:table-cell">Phone</th>
              <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 md:table-cell">Email</th>
              <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 lg:table-cell">Notes</th>
              <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="customer in customers" :key="customer.id" class="hover:bg-slate-50">
              <td class="px-5 py-3.5">
                <p class="font-medium text-slate-900">{{ customer.name }}</p>
                <p class="text-xs text-slate-500 sm:hidden">{{ customer.phone || customer.email || '—' }}</p>
              </td>
              <td class="hidden px-5 py-3.5 text-sm text-slate-600 sm:table-cell">{{ customer.phone || '—' }}</td>
              <td class="hidden px-5 py-3.5 text-sm text-slate-600 md:table-cell">{{ customer.email || '—' }}</td>
              <td class="hidden max-w-xs px-5 py-3.5 text-sm text-slate-600 lg:table-cell">
                <span class="block truncate">{{ customer.notes || '—' }}</span>
              </td>
              <td class="px-5 py-3.5 text-right">
                <div v-if="canWrite" class="flex justify-end gap-2">
                  <button
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                    @click="openEdit(customer)"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
                    @click="confirmTarget = customer"
                  >
                    Delete
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create / edit form -->
    <Modal
      v-if="showForm"
      :title="editing ? 'Edit customer' : 'Add customer'"
      size="lg"
      @close="closeForm"
    >
      <div
        v-if="formMessage"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ formMessage }}
      </div>

      <form id="customer-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitForm">
        <div class="sm:col-span-2">
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
          <label class="mb-1 block text-sm font-medium text-slate-700">Notes</label>
          <textarea
            v-model="form.notes"
            rows="3"
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
          form="customer-form"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Create customer' }}
        </button>
      </template>
    </Modal>

    <!-- Delete confirm -->
    <ConfirmDialog
      v-if="confirmTarget"
      title="Delete customer"
      :message="`Delete “${confirmTarget.name}”? This cannot be undone.`"
      :loading="deleting"
      @confirm="confirmDelete"
      @cancel="confirmTarget = null"
    />
  </div>
</template>
