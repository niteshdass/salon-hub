<script>
// The two dollar fields a counter payment sends. Split out so the empty-tip
// coercion (a blank field must send 0, never '' or NaN) has a test that
// doesn't need to mount the component. A tip is always its own key — never
// folded into `amount`, since it sits outside the balance it settles.
//
// `paidInFull` zero-locks Amount here, not just in the template: once a
// booking is settled, PaymentController::store() has nothing capping Amount
// against the remaining balance, so a stray value there would drive
// balance_due negative while the invoice still claims "Paid in full". A tip
// must still get through — that is the whole point of a settled booking
// reaching this form at all — so only `amount` is clamped.
export function buildPaymentPayload(form, paidInFull) {
  return {
    amount: paidInFull ? 0 : (form.amount === '' ? 0 : Number(form.amount)),
    tip_amount: form.tip_amount === '' ? 0 : Number(form.tip_amount),
    method: form.method,
    reference: form.reference || null,
  }
}
</script>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import Modal from './Modal.vue'

const props = defineProps({
  appointment: { type: Object, required: true },
})
const emit = defineEmits(['close', 'changed'])

const authStore = useAuthStore()
// Owner/manager may remove a recorded payment or confirm a pending deposit;
// anyone may take a payment.
const canDelete = computed(() => authStore.canManageOperations)
const canVerify = computed(() => authStore.canManageOperations)
const canRefund = computed(() => authStore.canManageOperations)

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
const form = reactive({ amount: '', tip_amount: '', method: 'cash', reference: '' })
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

// Once the balance is settled there is nothing left for Amount to do — keep
// it visually locked at 0 rather than merely uneditable, so a lingering
// typed value from before the booking got paid off elsewhere can't confuse
// what is about to be submitted.
watch(
  () => invoice.value?.paid_in_full,
  (paidInFull) => {
    if (paidInFull) form.amount = '0'
  },
)

async function record() {
  saving.value = true
  formMessage.value = ''
  formErrors.value = {}
  try {
    await api.post(`${base.value}/payments`, buildPaymentPayload(form, invoice.value?.paid_in_full))
    form.amount = ''
    form.tip_amount = ''
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

/* --------------------------- Print / download --------------------------- */
function esc(value) {
  return String(value ?? '').replace(/[&<>"]/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c],
  )
}

// Render the invoice into its own document and open the print dialog, from
// which the browser can save a PDF. Keeps us free of a server-side PDF stack.
function printInvoice() {
  const inv = invoice.value
  if (!inv) return

  const rows = inv.line_items
    .map(
      (item) =>
        `<tr><td>${esc(item.description)}</td><td class="r">${esc(money(item.amount))}</td></tr>`,
    )
    .join('')

  const payments = inv.payments.length
    ? `<h3>Payments</h3><table class="pay">${inv.payments
        .map(
          (p) =>
            `<tr><td>${esc(money(p.amount))}</td><td>${esc(methodLabel(p.method))}</td>` +
            `<td>${esc(p.reference || '')}</td><td class="r">${esc(p.recorded_by || '')}</td></tr>`,
        )
        .join('')}</table>`
    : ''

  const balanceLine = inv.paid_in_full
    ? `<div class="total paid">Paid in full</div>`
    : `<div class="total">Balance due <span>${esc(money(inv.balance_due))}</span></div>`

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(inv.number)}</title>
<style>
  * { box-sizing: border-box; }
  body { font: 14px/1.5 -apple-system, Segoe UI, Roboto, sans-serif; color: #0f172a; margin: 40px; }
  header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin: 0; }
  h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin: 24px 0 8px; }
  .muted { color: #64748b; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  td, th { padding: 8px 4px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  .r { text-align: right; }
  .totals { margin-top: 16px; margin-left: auto; width: 240px; }
  .totals div { display: flex; justify-content: space-between; padding: 4px 0; color: #475569; }
  .total { font-weight: 700; font-size: 16px; color: #0f172a; border-top: 2px solid #0f172a; margin-top: 8px; padding-top: 8px; }
  .total.paid { color: #059669; border-color: #059669; }
  .pay td { font-size: 13px; color: #475569; }
  footer { margin-top: 40px; text-align: center; color: #94a3b8; font-size: 11px; }
</style></head><body>
  <header>
    <div>
      <h1>${esc(inv.salon.name || 'Salon')}</h1>
      <div class="muted">${esc(inv.salon.email || '')}</div>
      <div class="muted">${esc(inv.salon.phone || '')}</div>
    </div>
    <div class="r">
      <h2>Invoice</h2>
      <div><strong>${esc(inv.number)}</strong></div>
      <div class="muted">${esc(inv.issued_on || '')}</div>
    </div>
  </header>

  <div class="muted">Billed to</div>
  <div><strong>${esc(inv.customer.name || '')}</strong></div>
  <div class="muted">${esc(inv.customer.phone || '')}</div>
  <div class="muted">${esc(inv.customer.email || '')}</div>

  <table>
    <thead><tr><th>Description</th><th class="r">Amount</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>

  <div class="totals">
    <div>Subtotal <span>${esc(money(inv.subtotal))}</span></div>
    <div>Paid <span>${esc(money(inv.amount_paid))}</span></div>
    ${balanceLine}
  </div>

  ${payments}

  <footer>Thank you for your visit.</footer>
  <script>window.onload = function () { window.print(); }<\/script>
</body></html>`

  const w = window.open('', '_blank', 'width=720,height=900')
  if (!w) {
    loadError.value = 'Allow pop-ups to print the invoice.'
    return
  }
  w.document.open()
  w.document.write(html)
  w.document.close()
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

/* ------------------------------ Verify ------------------------------ */
// A customer's online deposit lands pending; confirming it (once the money is
// seen) is what lets it count toward the balance.
const verifyingId = ref(null)

async function verify(payment) {
  verifyingId.value = payment.id
  try {
    await api.post(`${base.value}/payments/${payment.id}/verify`)
    await load()
    emit('changed')
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not verify the payment.').message
  } finally {
    verifyingId.value = null
  }
}

/* ------------------------------ Refund ------------------------------ */
// Return a captured online deposit to the customer through the gateway.
const refundingId = ref(null)

async function refund(payment) {
  if (!window.confirm('Refund this online deposit to the customer?')) return
  refundingId.value = payment.id
  try {
    await api.post(`${base.value}/payments/${payment.id}/refund`)
    await load()
    emit('changed')
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not refund the payment.').message
  } finally {
    refundingId.value = null
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
          v-if="Number(invoice.amount_pending) > 0"
          class="flex justify-between text-amber-600"
        >
          <dt>Pending verification</dt>
          <dd>{{ money(invoice.amount_pending) }}</dd>
        </div>
        <div
          class="flex justify-between border-t border-slate-200 pt-1.5 text-base font-semibold"
          :class="invoice.paid_in_full ? 'text-emerald-600' : 'text-slate-900'"
        >
          <dt>{{ invoice.paid_in_full ? 'Paid in full' : 'Balance due' }}</dt>
          <dd>{{ money(invoice.balance_due) }}</dd>
        </div>
        <!-- Tips are the staff member's, not the salon's balance, so they are
             shown next to it rather than folded in. -->
        <div v-if="Number(invoice.tips) > 0" class="flex justify-between text-slate-600">
          <dt>Tips</dt>
          <dd>{{ money(invoice.tips) }}</dd>
        </div>
        <div class="flex justify-between text-slate-600">
          <dt>Total collected</dt>
          <dd>{{ money(invoice.total_collected) }}</dd>
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
              <span v-if="p.transaction_id" class="text-slate-400">· {{ p.transaction_id }}</span>
              <span
                v-if="p.source === 'gateway'"
                class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700"
              >
                Online
              </span>
              <span
                v-if="p.status === 'pending'"
                class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700"
              >
                Pending
              </span>
              <span
                v-if="p.status === 'refunded'"
                class="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-medium text-slate-600"
              >
                Refunded
              </span>
            </span>
            <span class="flex items-center gap-3">
              <span v-if="p.recorded_by" class="text-xs text-slate-400">{{ p.recorded_by }}</span>
              <button
                v-if="canVerify && p.status === 'pending'"
                type="button"
                :disabled="verifyingId === p.id"
                class="text-xs font-medium text-emerald-600 hover:text-emerald-700 disabled:opacity-50"
                @click="verify(p)"
              >
                {{ verifyingId === p.id ? 'Verifying…' : 'Verify' }}
              </button>
              <button
                v-if="canRefund && p.source === 'gateway' && p.status === 'verified'"
                type="button"
                :disabled="refundingId === p.id"
                class="text-xs font-medium text-sky-600 hover:text-sky-700 disabled:opacity-50"
                @click="refund(p)"
              >
                {{ refundingId === p.id ? 'Refunding…' : 'Refund' }}
              </button>
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

      <!-- Record a payment. Stays open even once the balance is settled — a
           tip is entered here and only here, and a fully-paid visit can still
           take a tip-only payment (amount 0, tip > 0). -->
      <form class="rounded-xl border border-slate-200 p-4" @submit.prevent="record">
        <p class="mb-3 text-sm font-semibold text-slate-700">
          {{ invoice.paid_in_full ? 'Add a tip' : 'Record a payment' }}
        </p>
        <p v-if="formMessage" class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ formMessage }}</p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600" for="amount">Amount</label>
            <div class="flex gap-1.5">
              <input
                id="amount"
                v-model="form.amount"
                type="number"
                step="0.01"
                min="0"
                :disabled="invoice.paid_in_full"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
              />
              <button
                v-if="!invoice.paid_in_full"
                type="button"
                class="whitespace-nowrap rounded-lg border border-slate-300 px-2 text-xs text-slate-600 hover:bg-slate-50"
                @click="fillBalance"
              >
                Full
              </button>
            </div>
            <p v-if="invoice.paid_in_full" class="mt-1 text-xs text-slate-400">Balance settled — only a tip can be recorded.</p>
            <p v-if="fieldError('amount')" class="mt-1 text-xs text-rose-600">{{ fieldError('amount') }}</p>
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600" for="tip">Tip</label>
            <input
              id="tip"
              v-model="form.tip_amount"
              type="number"
              step="0.01"
              min="0"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <p v-if="fieldError('tip_amount')" class="mt-1 text-xs text-rose-600">{{ fieldError('tip_amount') }}</p>
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
        v-if="invoice"
        type="button"
        class="mr-auto inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
        @click="printInvoice"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" />
        </svg>
        Print / Download
      </button>
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
