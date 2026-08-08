<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PageHeader from '@/components/PageHeader.vue'

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
    <PageHeader
      title="Customers"
      :subtitle="`${customers.length} customer${customers.length === 1 ? '' : 's'} in your book`"
    >
      <template #actions>
        <button v-if="canWrite" type="button" class="sh-btn sh-btn-primary" @click="openCreate">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          Add customer
        </button>
      </template>
    </PageHeader>

    <div
      v-if="listError"
      class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
    >
      {{ listError }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="sh-card p-10 text-center text-sm text-ink/60">
      Loading customers…
    </div>

    <!-- Empty -->
    <div v-else-if="customers.length === 0" class="sh-empty">
      <p class="font-medium text-ink">No customers yet</p>
      <p class="mt-1">Add your first customer to get started.</p>
      <button v-if="canWrite" type="button" class="sh-btn sh-btn-primary mt-4" @click="openCreate">
        Add customer
      </button>
    </div>

    <!-- List. The four columns plus the row actions need more width than a
         phone has, so below md the table is replaced by a stacked card list
         rather than left to scroll sideways behind an overlay scrollbar that
         renders no affordance. Every field and every control appears in both
         branches. -->
    <template v-else>
      <div class="sh-card hidden overflow-x-auto md:block">
        <table class="sh-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Phone</th>
              <th>Email</th>
              <th>Notes</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="customer in customers" :key="customer.id">
              <td class="font-medium text-ink">{{ customer.name }}</td>
              <td class="text-ink/75">{{ customer.phone || '—' }}</td>
              <td class="text-ink/75">{{ customer.email || '—' }}</td>
              <td class="max-w-xs text-ink/75">
                <span class="block truncate">{{ customer.notes || '—' }}</span>
              </td>
              <td class="text-right whitespace-nowrap">
                <div v-if="canWrite" class="inline-flex items-center gap-1">
                  <button type="button" class="sh-btn px-2.5 py-1 text-xs" @click="openEdit(customer)">
                    Edit
                  </button>
                  <button
                    type="button"
                    class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
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

      <!-- Same data, same handlers, stacked so nothing sits off the right
           edge of a 390px viewport. -->
      <div class="space-y-3 md:hidden">
        <div v-for="customer in customers" :key="customer.id" class="sh-card p-5">
          <p class="font-medium text-ink">{{ customer.name }}</p>

          <dl class="mt-2 grid grid-cols-1 gap-y-1 text-sm">
            <div class="flex gap-2">
              <dt class="w-14 shrink-0 text-ink/40">Phone</dt>
              <dd class="truncate text-ink/75">{{ customer.phone || '—' }}</dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-14 shrink-0 text-ink/40">Email</dt>
              <dd class="truncate text-ink/75">{{ customer.email || '—' }}</dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-14 shrink-0 text-ink/40">Notes</dt>
              <dd class="truncate text-ink/75">{{ customer.notes || '—' }}</dd>
            </div>
          </dl>

          <div v-if="canWrite" class="mt-4 flex justify-end gap-1 border-t border-ink/10 pt-4">
            <button type="button" class="sh-btn px-2.5 py-1 text-xs" @click="openEdit(customer)">
              Edit
            </button>
            <button
              type="button"
              class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
              @click="confirmTarget = customer"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
    </template>

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
          <label class="sh-label">Name <span class="text-rose-500">*</span></label>
          <input v-model="form.name" type="text" required class="sh-input" placeholder="Jane Doe" />
          <p v-if="formErrors.name" class="sh-error">{{ formErrors.name[0] }}</p>
        </div>

        <div>
          <label class="sh-label">Phone</label>
          <input v-model="form.phone" type="text" class="sh-input" />
          <p v-if="formErrors.phone" class="sh-error">{{ formErrors.phone[0] }}</p>
        </div>

        <div>
          <label class="sh-label">Email</label>
          <input v-model="form.email" type="email" class="sh-input" />
          <p v-if="formErrors.email" class="sh-error">{{ formErrors.email[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="sh-label">Notes</label>
          <textarea v-model="form.notes" rows="3" class="sh-input" placeholder="Optional details"></textarea>
          <p v-if="formErrors.notes" class="sh-error">{{ formErrors.notes[0] }}</p>
        </div>
      </form>

      <template #footer>
        <button type="button" class="sh-btn" @click="closeForm">Cancel</button>
        <button type="submit" form="customer-form" :disabled="saving" class="sh-btn sh-btn-primary">
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
