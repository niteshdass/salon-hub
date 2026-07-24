<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from './Modal.vue'

const props = defineProps({
  appointment: { type: Object, required: true },
})
const emit = defineEmits(['close', 'changed'])

const authStore = useAuthStore()
// Owner/manager may remove a recorded payment; anyone may take one.
const canDelete = computed(() => authStore.canManageOperations)

const currency = computed(() => authStore.organization?.currency || 'USD')
function money(amount) {
  const value = Number(amount ?? 0)
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value }).format(value)
  } catch {
    return `${currency.value} ${value.toFixed(2)}`
  }
}

const METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'online', label: 'Online' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'other', label: 'Other' },
]
function methodLabel(value) {
  return METHODS.find((m) => m.value === value)?.label || value
}

const invoice = ref(null)
const loading = ref(false)
const loadError = ref('')

const base = computed(() => `/appointments/${props.appointment.id}`)

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get(`${base.value}/invoice`)
    invoice.value = data.data
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load the invoice.').message
  } finally {
    loading.value = false
  }
}

/* ------------------------------ Record ------------------------------ */
const form = reactive({ amount: '', method: 'cash', reference: '' })
const saving = ref(false)
const formMessage = ref('')
const formErrors = ref({})

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

function fillBalance() {
  if (invoice.value) form.amount = invoice.value.balance_due
}

async function record() {
  saving.value = true
  formMessage.value = ''
  formErrors.value = {}
  try {
    await api.post(`${base.value}/payments`, {
      amount: Number(form.amount),
      method: form.method,
      reference: form.reference || null,
    })
    form.amount = ''
    form.reference = ''
    await load()
    emit('changed')
  } catch (err) {
    const parsed = parseApiError(err, 'Could not record the payment.')
    formMessage.value = parsed.message
    formErrors.value = parsed.errors
  } finally {
    saving.value = false
  }
}

/* ------------------------------ Delete ------------------------------ */
const removingId = ref(null)

async function remove(payment) {
  removingId.value = payment.id
  try {
    await api.delete(`${base.value}/payments/${payment.id}`)
    await load()
    emit('changed')
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not remove the payment.').message
  } finally {
    removingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <Modal :title="invoice ? invoice.number : 'Invoice'" size="lg" @close="emit('close')">
    <p v-if="loadError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </p>

    <p v-if="loading && !invoice" class="py-6 text-center text-sm text-slate-500">Loading…</p>

    <div v-else-if="invoice" class="space-y-6">
      <!-- Header: who + when -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-slate-900">{{ invoice.customer.name }}</p>
          <p v-if="invoice.customer.phone" class="text-xs text-slate-500">{{ invoice.customer.phone }}</p>
        </div>
        <div class="text-right text-xs text-slate-500">
          <p>{{ invoice.salon.name }}</p>
          <p v-if="invoice.issued_on">{{ invoice.issued_on }}</p>
        </div>
      </div>

      <!-- Line items -->
      <div class="overflow-hidden rounded-xl border border-slate-200">
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="(item, i) in invoice.line_items" :key="i" class="border-b border-slate-100 last:border-0">
              <td class="px-4 py-2.5 text-slate-700">{{ item.description }}</td>
              <td class="px-4 py-2.5 text-right font-medium text-slate-900">{{ money(item.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Totals -->
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between text-slate-600">
          <dt>Subtotal</dt>
          <dd>{{ money(invoice.subtotal) }}</dd>
        </div>
        <div class="flex justify-between text-slate-600">
          <dt>Paid</dt>
          <dd>{{ money(invoice.amount_paid) }}</dd>
        </div>
        <div
          class="flex justify-between border-t border-slate-200 pt-1.5 text-base font-semibold"
          :class="invoice.paid_in_full ? 'text-emerald-600' : 'text-slate-900'"
        >
          <dt>{{ invoice.paid_in_full ? 'Paid in full' : 'Balance due' }}</dt>
          <dd>{{ money(invoice.balance_due) }}</dd>
        </div>
      </dl>

      <!-- Payment history -->
      <div v-if="invoice.payments.length">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Payments</p>
        <ul class="space-y-1.5">
          <li
            v-for="p in invoice.payments"
            :key="p.id"
            class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm"
          >
            <span class="text-slate-700">
              {{ money(p.amount) }}
              <span class="text-slate-400">· {{ methodLabel(p.method) }}</span>
              <span v-if="p.reference" class="text-slate-400">· {{ p.reference }}</span>
            </span>
            <span class="flex items-center gap-3">
              <span v-if="p.recorded_by" class="text-xs text-slate-400">{{ p.recorded_by }}</span>
              <button
                v-if="canDelete"
                type="button"
                :disabled="removingId === p.id"
                class="text-xs font-medium text-rose-600 hover:text-rose-700 disabled:opacity-50"
                @click="remove(p)"
              >
                Remove
              </button>
            </span>
          </li>
        </ul>
      </div>

      <!-- Record a payment -->
      <form v-if="!invoice.paid_in_full" class="rounded-xl border border-slate-200 p-4" @submit.prevent="record">
        <p class="mb-3 text-sm font-semibold text-slate-700">Record a payment</p>
        <p v-if="formMessage" class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ formMessage }}</p>

        <div class="grid gap-3 sm:grid-cols-3">
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Amount</label>
            <div class="flex gap-1.5">
              <input
                v-model="form.amount"
                type="number"
                step="0.01"
                min="0.01"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
              <button
                type="button"
                class="whitespace-nowrap rounded-lg border border-slate-300 px-2 text-xs text-slate-600 hover:bg-slate-50"
                @click="fillBalance"
              >
                Full
              </button>
            </div>
            <p v-if="fieldError('amount')" class="mt-1 text-xs text-rose-600">{{ fieldError('amount') }}</p>
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Method</label>
            <select v-model="form.method" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
              <option v-for="m in METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Reference</label>
            <input
              v-model="form.reference"
              type="text"
              placeholder="Optional"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
        </div>

        <div class="mt-3 flex justify-end">
          <button
            type="submit"
            :disabled="saving"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60"
          >
            {{ saving ? 'Saving…' : 'Record payment' }}
          </button>
        </div>
      </form>
    </div>

    <template #footer>
      <button
        type="button"
        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
        @click="emit('close')"
      >
        Close
      </button>
    </template>
  </Modal>
</template>
