<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'

const authStore = useAuthStore()
const currency = computed(() => authStore.organization?.currency || 'USD')

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const formMessage = ref('')
const formErrors = ref({})
const savedOk = ref(false)

// Reported by the API: whether an online gateway is configured (secrets never
// come back — only their presence).
const gateway = ref('none')
const hasGatewayCredentials = ref(false)

const form = reactive({
  deposit_type: 'none',
  deposit_value: '',
  manual_enabled: false,
  manual_account_number: '',
  manual_instructions: '',
})

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

function apply(settings) {
  const s = settings || {}
  form.deposit_type = s.deposit_type || 'none'
  // Show a blank field for a zero/no deposit rather than "0.00".
  form.deposit_value = s.deposit_type && s.deposit_type !== 'none' ? s.deposit_value : ''
  form.manual_enabled = !!s.manual_enabled
  form.manual_account_number = s.manual_account_number || ''
  form.manual_instructions = s.manual_instructions || ''
  gateway.value = s.gateway || 'none'
  hasGatewayCredentials.value = !!s.has_gateway_credentials
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/settings/payments')
    apply(data.data)
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load payment settings.').message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  savedOk.value = false
  formMessage.value = ''
  formErrors.value = {}
  try {
    const { data } = await api.put('/settings/payments', {
      deposit_type: form.deposit_type,
      // Only meaningful when a deposit is charged; the API forces it to 0 for "none".
      deposit_value: form.deposit_type === 'none' ? 0 : Number(form.deposit_value || 0),
      manual_enabled: form.manual_enabled,
      manual_account_number: form.manual_account_number || null,
      manual_instructions: form.manual_instructions || null,
    })
    apply(data.data)
    savedOk.value = true
  } catch (err) {
    const parsed = parseApiError(err, 'Could not save payment settings.')
    formMessage.value = parsed.message
    formErrors.value = parsed.errors
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <p v-if="loadError" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ loadError }}
    </p>

    <form
      v-if="!loading"
      class="max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
      @submit.prevent="save"
    >
      <!-- Deposit policy -->
      <fieldset class="space-y-3">
        <legend class="text-sm font-semibold text-slate-800">Booking deposit</legend>
        <p class="text-xs text-slate-500">
          Require customers to pay a deposit before a public booking is confirmed.
        </p>

        <div class="flex flex-wrap gap-4">
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.deposit_type" type="radio" value="none" class="text-indigo-600" /> No deposit
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.deposit_type" type="radio" value="percent" class="text-indigo-600" /> Percentage
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.deposit_type" type="radio" value="fixed" class="text-indigo-600" /> Fixed amount
          </label>
        </div>

        <div v-if="form.deposit_type !== 'none'" class="max-w-xs">
          <label class="mb-1 block text-xs font-medium text-slate-600">
            {{ form.deposit_type === 'percent' ? 'Percentage of total' : `Amount (${currency})` }}
          </label>
          <div class="flex items-center gap-2">
            <input
              v-model="form.deposit_value"
              type="number"
              step="0.01"
              min="0.01"
              :max="form.deposit_type === 'percent' ? 100 : undefined"
              class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
            />
            <span class="text-sm text-slate-500">{{ form.deposit_type === 'percent' ? '%' : currency }}</span>
          </div>
          <p v-if="fieldError('deposit_value')" class="mt-1 text-xs text-red-600">
            {{ fieldError('deposit_value') }}
          </p>
        </div>
      </fieldset>

      <!-- Manual transfer -->
      <fieldset class="space-y-3 rounded-xl border border-slate-200 p-4">
        <legend class="px-1 text-sm font-semibold text-slate-700">Manual transfer</legend>
        <label class="flex items-center gap-3">
          <input v-model="form.manual_enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600" />
          <span class="text-sm text-slate-800">
            Accept a bank / mobile-wallet transfer and a transaction reference
          </span>
        </label>

        <div v-if="form.manual_enabled" class="space-y-3">
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Account / wallet number</label>
            <input
              v-model="form.manual_account_number"
              type="text"
              placeholder="e.g. bKash 017XXXXXXXX or bank account no."
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <p v-if="fieldError('manual_account_number')" class="mt-1 text-xs text-red-600">
              {{ fieldError('manual_account_number') }}
            </p>
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">
              Instructions <span class="font-normal text-slate-400">(shown to the customer)</span>
            </label>
            <textarea
              v-model="form.manual_instructions"
              rows="3"
              placeholder="How to send the transfer and where to find the reference number."
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            ></textarea>
            <p v-if="fieldError('manual_instructions')" class="mt-1 text-xs text-red-600">
              {{ fieldError('manual_instructions') }}
            </p>
          </div>
          <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
            Transfers arrive as <strong>pending</strong> payments. Verify each one from the booking's
            invoice once you confirm the money landed.
          </p>
        </div>
      </fieldset>

      <!-- Online gateway (next phase) -->
      <fieldset class="space-y-2 rounded-xl border border-dashed border-slate-200 p-4">
        <legend class="px-1 text-sm font-semibold text-slate-500">Online gateway</legend>
        <p class="text-xs text-slate-500">
          SSLCommerz card / mobile-banking payments
          <span v-if="hasGatewayCredentials" class="ml-1 text-emerald-600">— connected</span>
          <span v-else class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">coming soon</span>
        </p>
      </fieldset>

      <p v-if="formMessage" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ formMessage }}</p>
      <p v-if="savedOk" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">Settings saved.</p>

      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60"
        >
          {{ saving ? 'Saving…' : 'Save settings' }}
        </button>
      </div>
    </form>

    <p v-else class="text-sm text-slate-500">Loading…</p>
  </div>
</template>
