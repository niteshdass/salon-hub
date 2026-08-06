<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const emit = defineEmits(['done', 'skip', 'back'])
const authStore = useAuthStore()

const THEMES = ['#4f46e5', '#0f766e', '#be123c', '#b45309', '#7c3aed', '#0369a1']

const about = ref('')
const themeColor = ref(THEMES[0])
const logoUrl = ref(null)
const saving = ref(false)
const uploading = ref(false)
const error = ref('')

const salonName = computed(() => authStore.organization?.name ?? 'Your salon')

onMounted(async () => {
  try {
    const { data } = await api.get('/settings/organization')
    about.value = data.data.about ?? ''
    themeColor.value = data.data.theme_color || THEMES[0]
    logoUrl.value = data.data.logo_url ?? null
  } catch (err) {
    error.value = parseApiError(err).message
  }
})

async function uploadLogo(event) {
  const file = event.target.files?.[0]
  if (!file) return
  uploading.value = true
  error.value = ''
  try {
    const body = new FormData()
    // Field name must match UploadOrganizationImageRequest — confirmed
    // against SettingsView.vue / SalonProfileSettings.vue: 'image'.
    body.append('image', file)
    const { data } = await api.post('/settings/organization/logo', body)
    logoUrl.value = data.data.logo_url
  } catch (err) {
    error.value = parseApiError(err).message
  } finally {
    uploading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    await api.put('/settings/organization', {
      about: about.value.trim() || null,
      theme_color: themeColor.value,
    })
    emit('done')
  } catch (err) {
    error.value = parseApiError(err).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="4"
    title="Make it yours"
    subtitle="Optional — your page already works without this."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div class="grid gap-5 sm:grid-cols-2">
      <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Your logo</label>
          <input type="file" accept="image/*" class="block w-full text-sm text-slate-600" @change="uploadLogo" />
          <p v-if="uploading" class="mt-1 text-sm text-slate-500">Uploading…</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">A line about your salon</label>
          <textarea
            v-model="about"
            rows="4"
            placeholder="We have cut hair on this street since 1998."
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700">Colour</label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="colour in THEMES"
              :key="colour"
              type="button"
              class="h-9 w-9 rounded-full ring-2 ring-offset-2 transition"
              :style="{ backgroundColor: colour }"
              :class="themeColor === colour ? 'ring-slate-900' : 'ring-transparent'"
              :aria-label="colour"
              @click="themeColor = colour"
            />
          </div>
        </div>
      </div>

      <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Preview</p>
        <div class="mt-3 overflow-hidden rounded-xl ring-1 ring-slate-200">
          <div class="h-20" :style="{ backgroundColor: themeColor }" />
          <div class="p-4">
            <img v-if="logoUrl" :src="logoUrl" alt="" class="h-12 w-12 rounded-full object-cover ring-2 ring-white" />
            <p class="mt-2 font-semibold text-slate-900">{{ salonName }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ about || 'Your salon story goes here.' }}</p>
          </div>
        </div>
      </div>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template #action>
      <button
        type="button"
        :disabled="saving"
        class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:bg-slate-300"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
    </template>
  </OnboardingLayout>
</template>
