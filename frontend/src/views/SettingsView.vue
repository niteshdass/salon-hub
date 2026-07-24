<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const formMessage = ref('')
const formErrors = ref({})
const savedOk = ref(false)

// Per-channel credential presence, reported by the API (never the secrets).
const hasCredentials = reactive({ whatsapp: false, sms: false })

const form = reactive({
  enabled: false,
  channel: 'whatsapp',
  lead_hours: 24,
  credentials: {
    phone_number_id: '',
    access_token: '',
    template_name: '',
    provider: '',
    from: '',
    api_key: '',
  },
})

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/settings/reminders')
    const s = data.data || {}
    form.enabled = !!s.enabled
    form.channel = s.channel || 'whatsapp'
    form.lead_hours = s.lead_hours ?? 24
    hasCredentials.whatsapp = !!s.has_credentials?.whatsapp
    hasCredentials.sms = !!s.has_credentials?.sms
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
    // Only send credential fields the user actually typed; blanks are omitted
    // so the backend keeps any stored secret.
    const credentials = {}
    for (const [k, v] of Object.entries(form.credentials)) {
      if (v !== '' && v != null) credentials[k] = v
    }

    const { data } = await api.put('/settings/reminders', {
      enabled: form.enabled,
      channel: form.channel,
      lead_hours: Number(form.lead_hours),
      credentials,
    })
    const s = data.data || {}
    hasCredentials.whatsapp = !!s.has_credentials?.whatsapp
    hasCredentials.sms = !!s.has_credentials?.sms
    // Clear the secret inputs after a successful save (they are write-only).
    form.credentials.access_token = ''
    form.credentials.api_key = ''
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
    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-slate-900">Settings</h1>
      <p class="mt-1 text-sm text-slate-500">Appointment reminders and channel connection.</p>
    </div>

    <p v-if="loadError" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ loadError }}
    </p>

    <form
      v-if="!loading"
      class="max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
      @submit.prevent="save"
    >
      <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Reminders run in log/test mode until a live provider is connected.
      </div>

      <!-- Enable -->
      <label class="flex items-center gap-3">
        <input v-model="form.enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600" />
        <span class="text-sm font-medium text-slate-800">Send pre-appointment reminders</span>
      </label>

      <!-- Channel -->
      <div>
        <span class="mb-2 block text-sm font-medium text-slate-800">Channel</span>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.channel" type="radio" value="whatsapp" class="text-indigo-600" /> WhatsApp
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.channel" type="radio" value="sms" class="text-indigo-600" /> SMS
          </label>
        </div>
        <p v-if="fieldError('channel')" class="mt-1 text-xs text-red-600">{{ fieldError('channel') }}</p>
      </div>

      <!-- Lead time -->
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-800">Lead time (hours before appointment)</label>
        <input
          v-model="form.lead_hours"
          type="number"
          min="1"
          max="168"
          class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
        />
        <p v-if="fieldError('lead_hours')" class="mt-1 text-xs text-red-600">{{ fieldError('lead_hours') }}</p>
      </div>

      <!-- WhatsApp connection -->
      <fieldset v-if="form.channel === 'whatsapp'" class="space-y-3 rounded-xl border border-slate-200 p-4">
        <legend class="px-1 text-sm font-semibold text-slate-700">
          WhatsApp connection
          <span v-if="hasCredentials.whatsapp" class="ml-2 text-xs font-normal text-emerald-600">connected</span>
        </legend>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Phone Number ID</label>
          <input v-model="form.credentials.phone_number_id" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Access Token</label>
          <input
            v-model="form.credentials.access_token"
            type="password"
            :placeholder="hasCredentials.whatsapp ? '•••••••• (leave blank to keep)' : ''"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Template name</label>
          <input v-model="form.credentials.template_name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
      </fieldset>

      <!-- SMS connection -->
      <fieldset v-else class="space-y-3 rounded-xl border border-slate-200 p-4">
        <legend class="px-1 text-sm font-semibold text-slate-700">
          SMS connection
          <span v-if="hasCredentials.sms" class="ml-2 text-xs font-normal text-emerald-600">connected</span>
        </legend>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Provider</label>
          <input v-model="form.credentials.provider" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">From number</label>
          <input v-model="form.credentials.from" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">API key</label>
          <input
            v-model="form.credentials.api_key"
            type="password"
            :placeholder="hasCredentials.sms ? '•••••••• (leave blank to keep)' : ''"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
          />
        </div>
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
