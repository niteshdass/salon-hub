<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PageHeader from '@/components/PageHeader.vue'

// The catalogue is maintained by owner/manager; staff read it only.
const authStore = useAuthStore()
const canWrite = computed(() => authStore.canManageOperations)

/* ------------------------------ Categories ------------------------------ */
const categories = ref([])
const categoriesLoading = ref(false)
const categoriesError = ref('')

const newCategoryName = ref('')
const addingCategory = ref(false)
const categoryError = ref('')

const categoryToDelete = ref(null)
const deletingCategory = ref(false)

async function loadCategories() {
  categoriesLoading.value = true
  categoriesError.value = ''
  try {
    const { data } = await api.get('/categories')
    categories.value = data.data || []
  } catch (err) {
    categoriesError.value = parseApiError(err, 'Could not load categories.').message
  } finally {
    categoriesLoading.value = false
  }
}

async function addCategory() {
  if (!newCategoryName.value.trim()) return
  addingCategory.value = true
  categoryError.value = ''
  try {
    await api.post('/categories', { name: newCategoryName.value.trim() })
    newCategoryName.value = ''
    await loadCategories()
  } catch (err) {
    const parsed = parseApiError(err)
    categoryError.value = parsed.errors.name?.[0] || parsed.message
  } finally {
    addingCategory.value = false
  }
}

async function confirmDeleteCategory() {
  if (!categoryToDelete.value) return
  deletingCategory.value = true
  try {
    await api.delete(`/categories/${categoryToDelete.value.id}`)
    categoryToDelete.value = null
    await Promise.all([loadCategories(), loadServices()])
  } catch (err) {
    categoriesError.value = parseApiError(err, 'Could not delete category.').message
    categoryToDelete.value = null
  } finally {
    deletingCategory.value = false
  }
}

/* ------------------------------- Services ------------------------------- */
const services = ref([])
const servicesLoading = ref(false)
const servicesError = ref('')

const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const formMessage = ref('')
const formErrors = ref({})

const form = reactive({
  name: '',
  category_id: '',
  description: '',
  duration: '',
  price: '',
  status: 'active',
})

const serviceToDelete = ref(null)
const deletingService = ref(false)

async function loadServices() {
  servicesLoading.value = true
  servicesError.value = ''
  try {
    const { data } = await api.get('/services')
    services.value = data.data || []
  } catch (err) {
    servicesError.value = parseApiError(err, 'Could not load services.').message
  } finally {
    servicesLoading.value = false
  }
}

function resetForm() {
  Object.assign(form, {
    name: '',
    category_id: '',
    description: '',
    duration: '',
    price: '',
    status: 'active',
  })
  formErrors.value = {}
  formMessage.value = ''
}

function openCreate() {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(service) {
  editing.value = service
  resetForm()
  Object.assign(form, {
    name: service.name || '',
    category_id: service.category?.id ?? '',
    description: service.description || '',
    duration: service.duration ?? '',
    price: service.price ?? '',
    status: service.status || 'active',
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
    category_id: form.category_id === '' ? null : Number(form.category_id),
    description: form.description || null,
    duration: form.duration === '' ? null : Number(form.duration),
    price: form.price === '' ? null : Number(form.price),
    status: form.status,
  }

  try {
    if (editing.value) {
      await api.put(`/services/${editing.value.id}`, payload)
    } else {
      await api.post('/services', payload)
    }
    closeForm()
    await Promise.all([loadServices(), loadCategories()])
  } catch (err) {
    const parsed = parseApiError(err)
    formErrors.value = parsed.errors
    formMessage.value = parsed.message
  } finally {
    saving.value = false
  }
}

async function confirmDeleteService() {
  if (!serviceToDelete.value) return
  deletingService.value = true
  try {
    await api.delete(`/services/${serviceToDelete.value.id}`)
    serviceToDelete.value = null
    await Promise.all([loadServices(), loadCategories()])
  } catch (err) {
    servicesError.value = parseApiError(err, 'Could not delete service.').message
    serviceToDelete.value = null
  } finally {
    deletingService.value = false
  }
}

function formatPrice(value) {
  if (value === null || value === undefined || value === '') return '—'
  const num = Number(value)
  return Number.isNaN(num) ? value : num.toFixed(2)
}

onMounted(() => {
  loadCategories()
  loadServices()
})
</script>

<template>
  <div>
    <PageHeader title="Services" subtitle="What customers can book.">
      <template #actions>
        <button v-if="canWrite" type="button" class="sh-btn sh-btn-primary" @click="openCreate">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          Add service
        </button>
      </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- Categories -->
      <section class="lg:col-span-1">
        <div class="sh-card p-5">
          <h2 class="font-display text-lg text-ink">Categories</h2>

          <form v-if="canWrite" class="mt-4 flex gap-2" @submit.prevent="addCategory">
            <input v-model="newCategoryName" type="text" placeholder="New category" class="sh-input" />
            <button
              type="submit"
              :disabled="addingCategory || !newCategoryName.trim()"
              class="sh-btn sh-btn-primary shrink-0"
            >
              Add
            </button>
          </form>
          <p v-if="categoryError" class="sh-error">{{ categoryError }}</p>

          <div class="mt-4">
            <p v-if="categoriesLoading" class="py-4 text-center text-sm text-ink/60">Loading…</p>
            <p v-else-if="categoriesError" class="py-3 text-sm text-rose-600">{{ categoriesError }}</p>
            <p v-else-if="categories.length === 0" class="py-4 text-center text-sm text-ink/60">
              No categories yet.
            </p>
            <ul v-else class="divide-y divide-ink/10">
              <li v-for="cat in categories" :key="cat.id" class="flex items-center justify-between py-2.5">
                <div>
                  <p class="text-sm font-medium text-ink">{{ cat.name }}</p>
                  <p class="text-xs text-ink/40">{{ cat.services_count ?? 0 }} service(s)</p>
                </div>
                <button
                  v-if="canWrite"
                  type="button"
                  class="rounded-full p-1.5 text-ink/40 transition hover:bg-rose-50 hover:text-rose-600"
                  aria-label="Delete category"
                  @click="categoryToDelete = cat"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                  </svg>
                </button>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Services -->
      <section class="lg:col-span-2">
        <h2 class="mb-4 font-display text-lg text-ink">All services</h2>

        <div
          v-if="servicesError"
          class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
        >
          {{ servicesError }}
        </div>

        <div v-if="servicesLoading" class="sh-card p-10 text-center text-sm text-ink/60">
          Loading services…
        </div>

        <div v-else-if="services.length === 0" class="sh-empty">
          <p class="font-medium text-ink">No services yet</p>
          <p class="mt-1">Add a service to display it here.</p>
        </div>

        <!-- List. Six columns do not fit a phone, so below md the table is
             replaced by a stacked card list rather than left to scroll
             sideways behind an overlay scrollbar that renders no affordance.
             Every field and every control appears in both branches. -->
        <template v-else>
          <div class="sh-card hidden overflow-x-auto md:block">
            <table class="sh-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Duration</th>
                  <th>Price</th>
                  <th>Status</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="service in services" :key="service.id">
                  <td class="font-medium text-ink">{{ service.name }}</td>
                  <td class="text-ink/75">{{ service.category?.name || '—' }}</td>
                  <td class="whitespace-nowrap text-ink/75">{{ service.duration ?? '—' }} min</td>
                  <td class="text-ink/75">{{ formatPrice(service.price) }}</td>
                  <td>
                    <span
                      class="sh-badge capitalize"
                      :class="service.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-ink/10 text-ink/60'"
                    >
                      {{ service.status || 'inactive' }}
                    </span>
                  </td>
                  <td class="text-right whitespace-nowrap">
                    <div v-if="canWrite" class="inline-flex items-center gap-1">
                      <button type="button" class="sh-btn px-2.5 py-1 text-xs" @click="openEdit(service)">
                        Edit
                      </button>
                      <button
                        type="button"
                        class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                        @click="serviceToDelete = service"
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
            <div v-for="service in services" :key="service.id" class="sh-card p-5">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-ink">{{ service.name }}</span>
                <span
                  class="sh-badge capitalize"
                  :class="service.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-ink/10 text-ink/60'"
                >
                  {{ service.status || 'inactive' }}
                </span>
              </div>

              <dl class="mt-2 grid grid-cols-1 gap-y-1 text-sm">
                <div class="flex gap-2">
                  <dt class="w-20 shrink-0 text-ink/40">Category</dt>
                  <dd class="truncate text-ink/75">{{ service.category?.name || '—' }}</dd>
                </div>
                <div class="flex gap-2">
                  <dt class="w-20 shrink-0 text-ink/40">Duration</dt>
                  <dd class="truncate text-ink/75">{{ service.duration ?? '—' }} min</dd>
                </div>
                <div class="flex gap-2">
                  <dt class="w-20 shrink-0 text-ink/40">Price</dt>
                  <dd class="truncate text-ink/75">{{ formatPrice(service.price) }}</dd>
                </div>
              </dl>

              <div v-if="canWrite" class="mt-4 flex justify-end gap-1 border-t border-ink/10 pt-4">
                <button type="button" class="sh-btn px-2.5 py-1 text-xs" @click="openEdit(service)">
                  Edit
                </button>
                <button
                  type="button"
                  class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                  @click="serviceToDelete = service"
                >
                  Delete
                </button>
              </div>
            </div>
          </div>
        </template>
      </section>
    </div>

    <!-- Service form -->
    <Modal
      v-if="showForm"
      :title="editing ? 'Edit service' : 'Add service'"
      size="lg"
      @close="closeForm"
    >
      <div
        v-if="formMessage"
        class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ formMessage }}
      </div>

      <form id="service-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitForm">
        <div class="sm:col-span-2">
          <label class="sh-label">Name <span class="text-rose-500">*</span></label>
          <input v-model="form.name" type="text" required class="sh-input" placeholder="Haircut" />
          <p v-if="formErrors.name" class="sh-error">{{ formErrors.name[0] }}</p>
        </div>

        <div>
          <label class="sh-label">Category</label>
          <select v-model="form.category_id" class="sh-input">
            <option value="">Uncategorized</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
          <p v-if="formErrors.category_id" class="sh-error">{{ formErrors.category_id[0] }}</p>
        </div>

        <div>
          <label class="sh-label">Status</label>
          <select v-model="form.status" class="sh-input">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <p v-if="formErrors.status" class="sh-error">{{ formErrors.status[0] }}</p>
        </div>

        <div>
          <label class="sh-label">Duration (min) <span class="text-rose-500">*</span></label>
          <input v-model="form.duration" type="number" min="0" required class="sh-input" placeholder="30" />
          <p v-if="formErrors.duration" class="sh-error">{{ formErrors.duration[0] }}</p>
        </div>

        <div>
          <label class="sh-label">Price <span class="text-rose-500">*</span></label>
          <input
            v-model="form.price"
            type="number"
            min="0"
            step="0.01"
            required
            class="sh-input"
            placeholder="25.00"
          />
          <p v-if="formErrors.price" class="sh-error">{{ formErrors.price[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="sh-label">Description</label>
          <textarea
            v-model="form.description"
            rows="3"
            class="sh-input"
            placeholder="Optional details"
          ></textarea>
          <p v-if="formErrors.description" class="sh-error">{{ formErrors.description[0] }}</p>
        </div>
      </form>

      <template #footer>
        <button type="button" class="sh-btn" @click="closeForm">Cancel</button>
        <button type="submit" form="service-form" :disabled="saving" class="sh-btn sh-btn-primary">
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Create service' }}
        </button>
      </template>
    </Modal>

    <!-- Delete confirms -->
    <ConfirmDialog
      v-if="serviceToDelete"
      title="Delete service"
      :message="`Delete “${serviceToDelete.name}”? This cannot be undone.`"
      :loading="deletingService"
      @confirm="confirmDeleteService"
      @cancel="serviceToDelete = null"
    />

    <ConfirmDialog
      v-if="categoryToDelete"
      title="Delete category"
      :message="`Delete “${categoryToDelete.name}”? Services in it will be uncategorized.`"
      :loading="deletingCategory"
      @confirm="confirmDeleteCategory"
      @cancel="categoryToDelete = null"
    />
  </div>
</template>
