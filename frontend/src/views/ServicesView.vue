<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

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
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-900">Services</h1>
      <p class="mt-1 text-sm text-slate-500">Organize your offerings into categories and services.</p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- Categories -->
      <section class="lg:col-span-1">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-base font-semibold text-slate-900">Categories</h2>

          <form class="mt-4 flex gap-2" @submit.prevent="addCategory">
            <input
              v-model="newCategoryName"
              type="text"
              placeholder="New category"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            />
            <button
              type="submit"
              :disabled="addingCategory || !newCategoryName.trim()"
              class="shrink-0 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              Add
            </button>
          </form>
          <p v-if="categoryError" class="mt-2 text-sm text-rose-600">{{ categoryError }}</p>

          <div class="mt-4">
            <p v-if="categoriesLoading" class="py-4 text-center text-sm text-slate-500">Loading…</p>
            <p v-else-if="categoriesError" class="py-3 text-sm text-rose-600">{{ categoriesError }}</p>
            <p v-else-if="categories.length === 0" class="py-4 text-center text-sm text-slate-500">
              No categories yet.
            </p>
            <ul v-else class="divide-y divide-slate-100">
              <li v-for="cat in categories" :key="cat.id" class="flex items-center justify-between py-2.5">
                <div>
                  <p class="text-sm font-medium text-slate-800">{{ cat.name }}</p>
                  <p class="text-xs text-slate-400">{{ cat.services_count ?? 0 }} service(s)</p>
                </div>
                <button
                  type="button"
                  class="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
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
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-base font-semibold text-slate-900">All services</h2>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
            @click="openCreate"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add service
          </button>
        </div>

        <div
          v-if="servicesError"
          class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
        >
          {{ servicesError }}
        </div>

        <div v-if="servicesLoading" class="rounded-2xl bg-white p-10 text-center text-sm text-slate-500 ring-1 ring-slate-200">
          Loading services…
        </div>

        <div
          v-else-if="services.length === 0"
          class="rounded-2xl bg-white p-10 text-center ring-1 ring-slate-200"
        >
          <p class="text-sm font-medium text-slate-900">No services yet</p>
          <p class="mt-1 text-sm text-slate-500">Add a service to display it here.</p>
        </div>

        <div v-else class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
              <thead class="bg-slate-50">
                <tr>
                  <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
                  <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:table-cell">Category</th>
                  <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Duration</th>
                  <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Price</th>
                  <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 sm:table-cell">Status</th>
                  <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="service in services" :key="service.id" class="hover:bg-slate-50">
                  <td class="px-5 py-3.5">
                    <p class="font-medium text-slate-900">{{ service.name }}</p>
                    <p class="text-xs text-slate-500 sm:hidden">{{ service.category?.name || 'Uncategorized' }}</p>
                  </td>
                  <td class="hidden px-5 py-3.5 text-sm text-slate-600 sm:table-cell">
                    {{ service.category?.name || '—' }}
                  </td>
                  <td class="px-5 py-3.5 text-sm text-slate-600">{{ service.duration ?? '—' }} min</td>
                  <td class="px-5 py-3.5 text-sm text-slate-600">{{ formatPrice(service.price) }}</td>
                  <td class="hidden px-5 py-3.5 sm:table-cell">
                    <span
                      class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                      :class="service.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                    >
                      {{ service.status || 'inactive' }}
                    </span>
                  </td>
                  <td class="px-5 py-3.5 text-right">
                    <div class="flex justify-end gap-2">
                      <button
                        type="button"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                        @click="openEdit(service)"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
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
        </div>
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
          <label class="mb-1 block text-sm font-medium text-slate-700">Name <span class="text-rose-500">*</span></label>
          <input
            v-model="form.name"
            type="text"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="Haircut"
          />
          <p v-if="formErrors.name" class="mt-1 text-sm text-rose-600">{{ formErrors.name[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
          <select
            v-model="form.category_id"
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          >
            <option value="">Uncategorized</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
          <p v-if="formErrors.category_id" class="mt-1 text-sm text-rose-600">{{ formErrors.category_id[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
          <select
            v-model="form.status"
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <p v-if="formErrors.status" class="mt-1 text-sm text-rose-600">{{ formErrors.status[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Duration (min) <span class="text-rose-500">*</span></label>
          <input
            v-model="form.duration"
            type="number"
            min="0"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="30"
          />
          <p v-if="formErrors.duration" class="mt-1 text-sm text-rose-600">{{ formErrors.duration[0] }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Price <span class="text-rose-500">*</span></label>
          <input
            v-model="form.price"
            type="number"
            min="0"
            step="0.01"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="25.00"
          />
          <p v-if="formErrors.price" class="mt-1 text-sm text-rose-600">{{ formErrors.price[0] }}</p>
        </div>

        <div class="sm:col-span-2">
          <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
          <textarea
            v-model="form.description"
            rows="3"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="Optional details"
          ></textarea>
          <p v-if="formErrors.description" class="mt-1 text-sm text-rose-600">{{ formErrors.description[0] }}</p>
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
          form="service-form"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
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
