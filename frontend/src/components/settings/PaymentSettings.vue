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

// Reported by the API: whether gateway secrets are on file (they never come
// back — only their presence).
const hasGatewayCredentials = ref(false)

const form = reactive({
  deposit_type: 'none',
  deposit_value: '',
  manual_enabled: false,
  manual_account_number: '',
  manual_instructions: '',
  gateway: 'none',
  gateway_sandbox: true,
  credentials: {
    store_id: '',
    store_passwd: '',
  },
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
  form.gateway = s.gateway || 'none'
  form.gateway_sandbox = s.gateway_sandbox !== false
  form.credentials.store_id = s.credentials?.store_id || ''
  // Write-only: the API never returns the secret.
  form.credentials.store_passwd = ''
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
    // Blank credential fields are omitted so a masked secret is never wiped
    // by a re-save; identifiers go as typed.
    const credentials = {}
    for (const [key, value] of Object.entries(form.credentials)) {
      if (value !== '' && value != null) credentials[key] = value
    }

    const { data } = await api.put('/settings/payments', {
      deposit_type: form.deposit_type,
      // Only meaningful when a deposit is charged; the API forces it to 0 for "none".
      deposit_value: form.deposit_type === 'none' ? 0 : Number(form.deposit_value || 0),
      manual_enabled: form.manual_enabled,
      manual_account_number: form.manual_account_number || null,
      manual_instructions: form.manual_instructions || null,
      gateway: form.gateway,
      gateway_sandbox: form.gateway_sandbox,
      credentials,
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
    <p v-if="loadError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </p>

    <form v-if="!loading" class="sh-card max-w-2xl space-y-6 p-6" @submit.prevent="save">
      <!-- Deposit policy -->
      <fieldset class="space-y-3">
        <legend class="text-sm font-semibold text-ink">Booking deposit</legend>
        <p class="text-xs text-ink/55">
          Require customers to pay a deposit before a public booking is confirmed.
        </p>

        <div class="flex flex-wrap gap-4">
          <label class="flex items-center gap-2 text-sm text-ink/75">
            <input v-model="form.deposit_type" type="radio" value="none" class="text-accent-600" /> No deposit
          </label>
          <label class="flex items-center gap-2 text-sm text-ink/75">
            <input v-model="form.deposit_type" type="radio" value="percent" class="text-accent-600" /> Percentage
          </label>
          <label class="flex items-center gap-2 text-sm text-ink/75">
            <input v-model="form.deposit_type" type="radio" value="fixed" class="text-accent-600" /> Fixed amount
          </label>
        </div>

        <div v-if="form.deposit_type !== 'none'" class="max-w-xs">
          <label class="sh-label text-xs">
            {{ form.deposit_type === 'percent' ? 'Percentage of total' : `Amount (${currency})` }}
          </label>
          <div class="flex items-center gap-2">
            <input
              v-model="form.deposit_value"
              type="number"
              step="0.01"
              min="0.01"
              :max="form.deposit_type === 'percent' ? 100 : undefined"
              class="sh-input w-40"
            />
            <span class="text-sm text-ink/60">{{ form.deposit_type === 'percent' ? '%' : currency }}</span>
          </div>
          <p v-if="fieldError('deposit_value')" class="sh-error">
            {{ fieldError('deposit_value') }}
          </p>
        </div>
      </fieldset>

      <!-- Manual transfer -->
      <fieldset class="space-y-3 rounded-xl border border-ink/10 p-4">
        <legend class="px-1 text-sm font-semibold text-ink">Manual transfer</legend>
        <label class="flex items-center gap-3">
          <input v-model="form.manual_enabled" type="checkbox" class="h-4 w-4 rounded border-ink/15 text-accent-600" />
          <span class="text-sm text-ink">
            Accept a bank / mobile-wallet transfer and a transaction reference
          </span>
        </label>

        <div v-if="form.manual_enabled" class="space-y-3">
          <div>
            <label class="sh-label text-xs">Account / wallet number</label>
            <input
              v-model="form.manual_account_number"
              type="text"
              placeholder="e.g. bKash 017XXXXXXXX or bank account no."
              class="sh-input"
            />
            <p v-if="fieldError('manual_account_number')" class="sh-error">
              {{ fieldError('manual_account_number') }}
            </p>
          </div>
          <div>
            <label class="sh-label text-xs">
              Instructions <span class="font-normal text-ink/40">(shown to the customer)</span>
            </label>
            <textarea
              v-model="form.manual_instructions"
              rows="3"
              placeholder="How to send the transfer and where to find the reference number."
              class="sh-input"
            ></textarea>
            <p v-if="fieldError('manual_instructions')" class="sh-error">
              {{ fieldError('manual_instructions') }}
            </p>
          </div>
          <p class="rounded-lg bg-paper px-3 py-2 text-xs text-ink/60">
            Transfers arrive as <strong>pending</strong> payments. Verify each one from the booking's
            invoice once you confirm the money landed.
          </p>
        </div>
      </fieldset>

      <!-- Online gateway -->
      <fieldset class="space-y-3 rounded-xl border border-ink/10 p-4">
        <legend class="px-1 text-sm font-semibold text-ink">
          Online gateway
          <span v-if="form.gateway !== 'none' && hasGatewayCredentials" class="ml-2 text-xs font-normal text-emerald-600">
            connected
          </span>
        </legend>
        <p class="text-xs text-ink/55">Let customers pay the deposit by card or mobile banking.</p>

        <div>
          <label class="sh-label text-xs">Provider</label>
          <select v-model="form.gateway" class="sh-input sm:w-64">
            <option value="none">None</option>
            <option value="sslcommerz">SSLCommerz</option>
          </select>
        </div>

        <div v-if="form.gateway === 'sslcommerz'" class="space-y-3">
          <label class="flex items-center gap-3">
            <input v-model="form.gateway_sandbox" type="checkbox" class="h-4 w-4 rounded border-ink/15 text-accent-600" />
            <span class="text-sm text-ink">
              Sandbox (test) mode
              <span class="text-xs text-ink/40">— turn off only with live credentials</span>
            </span>
          </label>

          <div>
            <label class="sh-label text-xs">Store ID</label>
            <input
              v-model="form.credentials.store_id"
              type="text"
              placeholder="e.g. glow5f0a1b2c3"
              class="sh-input font-mono"
            />
            <p v-if="fieldError('credentials.store_id')" class="sh-error">
              {{ fieldError('credentials.store_id') }}
            </p>
          </div>

          <div>
            <label class="sh-label text-xs">Store password</label>
            <input
              v-model="form.credentials.store_passwd"
              type="password"
              :placeholder="hasGatewayCredentials ? '•••••••• (leave blank to keep)' : ''"
              class="sh-input"
            />
            <p v-if="fieldError('credentials.store_passwd')" class="sh-error">
              {{ fieldError('credentials.store_passwd') }}
            </p>
          </div>

          <p class="rounded-lg bg-paper px-3 py-2 text-xs text-ink/60">
            <template v-if="form.gateway_sandbox">
              Get your sandbox Store ID and password from the SSLCommerz developer dashboard. You can
              select the provider now and add the keys later.
            </template>
            <template v-else>
              Live mode charges real cards. Enter your live Store ID and password from the SSLCommerz
              merchant panel — the sandbox keys will not work here.
            </template>
          </p>
        </div>
      </fieldset>

      <p v-if="formMessage" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ formMessage }}
      </p>
      <p v-if="savedOk" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        Settings saved.
      </p>

      <div class="flex justify-end">
        <button type="submit" :disabled="saving" class="sh-btn sh-btn-primary">
          {{ saving ? 'Saving…' : 'Save settings' }}
        </button>
      </div>
    </form>

    <p v-else class="text-sm text-ink/60">Loading…</p>
  </div>
</template>
