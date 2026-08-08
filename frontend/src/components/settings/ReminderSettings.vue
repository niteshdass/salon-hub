<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const formMessage = ref('')
const formErrors = ref({})
const savedOk = ref(false)

// Reported by the API: whether an auth token is on file, and whether the
// platform's own Twilio account would carry reminders regardless.
const connected = ref(false)
const platformFallback = ref(false)

const form = reactive({
  enabled: false,
  channel: 'whatsapp',
  lead_hours: 24,
  credentials: {
    account_sid: '',
    auth_token: '',
    from: '',
    whatsapp_from: '',
    messaging_service_sid: '',
  },
})

// Nothing connected here and nothing behind it: reminders only reach the log.
const dryRun = computed(() => !connected.value && !platformFallback.value)

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

function apply(settings) {
  const s = settings || {}
  form.enabled = !!s.enabled
  form.channel = s.channel || 'whatsapp'
  form.lead_hours = s.lead_hours ?? 24
  form.credentials.account_sid = s.account_sid || ''
  form.credentials.from = s.from || ''
  form.credentials.whatsapp_from = s.whatsapp_from || ''
  form.credentials.messaging_service_sid = s.messaging_service_sid || ''
  // Write-only: the API never sends it back.
  form.credentials.auth_token = ''
  connected.value = !!s.has_credentials?.twilio
  platformFallback.value = !!s.platform_fallback
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/settings/reminders')
    apply(data.data)
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load settings.').message
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
    // Blank fields are omitted so a masked auth token is never wiped by a
    // re-save; identifiers are sent as typed.
    const credentials = {}
    for (const [key, value] of Object.entries(form.credentials)) {
      if (value !== '' && value != null) credentials[key] = value
    }

    const { data } = await api.put('/settings/reminders', {
      enabled: form.enabled,
      channel: form.channel,
      lead_hours: Number(form.lead_hours),
      credentials,
    })
    apply(data.data)
    savedOk.value = true
  } catch (err) {
    const parsed = parseApiError(err, 'Could not save settings.')
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
      <div v-if="dryRun" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
        No Twilio account connected — reminders are written to the log instead of sent.
      </div>
      <div v-else-if="!connected" class="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Sending through the SalonHub Twilio account. Connect your own below to send from your number.
      </div>

      <!-- Enable -->
      <label class="flex items-center gap-3">
        <input v-model="form.enabled" type="checkbox" class="h-4 w-4 rounded border-ink/15 text-accent-600" />
        <span class="text-sm font-medium text-ink">Send pre-appointment reminders</span>
      </label>

      <!-- Channel -->
      <div>
        <span class="sh-label">Channel</span>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 text-sm text-ink/75">
            <input v-model="form.channel" type="radio" value="whatsapp" class="text-accent-600" /> WhatsApp
          </label>
          <label class="flex items-center gap-2 text-sm text-ink/75">
            <input v-model="form.channel" type="radio" value="sms" class="text-accent-600" /> SMS
          </label>
        </div>
        <p class="mt-1 text-xs text-ink/55">Both ride the same Twilio account.</p>
        <p v-if="fieldError('channel')" class="sh-error">{{ fieldError('channel') }}</p>
      </div>

      <!-- Lead time -->
      <div>
        <label class="sh-label">Lead time (hours before appointment)</label>
        <input v-model="form.lead_hours" type="number" min="1" max="168" class="sh-input w-40" />
        <p v-if="fieldError('lead_hours')" class="sh-error">{{ fieldError('lead_hours') }}</p>
      </div>

      <!-- Twilio connection -->
      <fieldset class="space-y-3 rounded-xl border border-ink/10 p-4">
        <legend class="px-1 text-sm font-semibold text-ink">
          Twilio account
          <span v-if="connected" class="ml-2 text-xs font-normal text-emerald-600">connected</span>
        </legend>

        <div>
          <label class="sh-label text-xs">Account SID</label>
          <input v-model="form.credentials.account_sid" type="text" placeholder="AC…" class="sh-input font-mono" />
          <p v-if="fieldError('credentials.account_sid')" class="sh-error">
            {{ fieldError('credentials.account_sid') }}
          </p>
        </div>

        <div>
          <label class="sh-label text-xs">Auth Token</label>
          <input
            v-model="form.credentials.auth_token"
            type="password"
            :placeholder="connected ? '•••••••• (leave blank to keep)' : ''"
            class="sh-input"
          />
          <p v-if="fieldError('credentials.auth_token')" class="sh-error">
            {{ fieldError('credentials.auth_token') }}
          </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="sh-label text-xs">SMS sender</label>
            <input v-model="form.credentials.from" type="text" placeholder="+15550111" class="sh-input" />
          </div>
          <div>
            <label class="sh-label text-xs">WhatsApp sender</label>
            <input v-model="form.credentials.whatsapp_from" type="text" placeholder="+14155238886" class="sh-input" />
          </div>
        </div>

        <div>
          <label class="sh-label text-xs">
            Messaging Service SID <span class="font-normal text-ink/40">(optional)</span>
          </label>
          <input
            v-model="form.credentials.messaging_service_sid"
            type="text"
            placeholder="MG…"
            class="sh-input font-mono"
          />
          <p class="mt-1 text-xs text-ink/55">
            When set, Twilio picks the sender and the numbers above are ignored.
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
