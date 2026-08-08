<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { THEME_SWATCHES } from '@/lib/theme'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

const authStore = useAuthStore()
const themeStore = useThemeStore()

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const formMessage = ref('')
const formErrors = ref({})
const savedOk = ref(false)

// Which image is uploading, so only that card shows a spinner.
const imageBusy = ref('')
const imageError = ref('')
const logoInput = ref(null)
const coverInput = ref(null)

const slug = ref('')
const logoUrl = ref(null)
const coverUrl = ref(null)

const form = reactive({
  name: '',
  email: '',
  phone: '',
  country: '',
  timezone: '',
  currency: '',
  theme_color: THEME_SWATCHES[0],
  about: '',
  facebook: '',
  instagram: '',
  website: '',
})

// The browser knows every zone; fall back to a free-text field on the
// handful of engines that do not expose the list.
const timezones = computed(() => {
  try {
    return Intl.supportedValuesOf('timeZone')
  } catch {
    return []
  }
})

const bookingUrl = computed(() =>
  slug.value ? `${window.location.origin}/book/${slug.value}` : '',
)

const siteUrl = computed(() =>
  slug.value ? `${window.location.origin}/salon/${slug.value}` : '',
)

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

function apply(data) {
  slug.value = data.slug || ''
  logoUrl.value = data.logo_url || null
  coverUrl.value = data.cover_image_url || null
  for (const key of Object.keys(form)) {
    form[key] = data[key] ?? (key === 'theme_color' ? '#6366f1' : '')
  }
  themeStore.setAccent(form.theme_color)
}

// Selecting a colour repaints the app at once — the owner judges it against
// the real sidebar rather than a swatch.
function chooseTheme(hex) {
  form.theme_color = hex
  themeStore.setAccent(hex)
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/settings/organization')
    apply(data.data || {})
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load the salon profile.').message
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
    const { data } = await api.put('/settings/organization', form)
    apply(data.data || {})
    savedOk.value = true
    // The sidebar and header read the org from the session.
    await authStore.fetchMe().catch(() => {})
  } catch (err) {
    const parsed = parseApiError(err, 'Could not save the salon profile.')
    formMessage.value = parsed.message
    formErrors.value = parsed.errors
  } finally {
    saving.value = false
  }
}

async function uploadImage(kind, event) {
  const file = event.target.files?.[0]
  if (!file) return

  imageBusy.value = kind
  imageError.value = ''
  try {
    const body = new FormData()
    body.append('image', file)
    const { data } = await api.post(`/settings/organization/${kind}`, body)
    apply(data.data || {})
    if (kind === 'logo') await authStore.fetchMe().catch(() => {})
  } catch (err) {
    imageError.value = parseApiError(err, 'Could not upload that image.').message
  } finally {
    imageBusy.value = ''
    const input = kind === 'logo' ? logoInput.value : coverInput.value
    // Reset so picking the same file again still fires a change.
    if (input) input.value = ''
  }
}

async function removeImage(kind) {
  imageBusy.value = kind
  imageError.value = ''
  try {
    const { data } = await api.delete(`/settings/organization/${kind}`)
    apply(data.data || {})
  } catch (err) {
    imageError.value = parseApiError(err, 'Could not remove that image.').message
  } finally {
    imageBusy.value = ''
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl space-y-6">
    <p v-if="loadError" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ loadError }}</p>

    <div v-if="loading" class="h-64 animate-pulse rounded-2xl bg-slate-100" />

    <template v-else>
      <!-- Branding -->
      <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-slate-900">Branding</h2>
        <p class="mt-1 text-sm text-slate-500">Shown across your public page and booking flow.</p>

        <p v-if="imageError" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ imageError }}</p>

        <div class="mt-5 grid gap-5 sm:grid-cols-[10rem_1fr]">
          <!-- Logo -->
          <div>
            <span class="mb-2 block text-xs font-medium text-slate-600">Logo</span>
            <div class="flex h-32 w-32 items-center justify-center overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200">
              <img v-if="logoUrl" :src="logoUrl" alt="Salon logo" class="h-full w-full object-cover" />
              <span v-else class="text-xs text-slate-400">No logo</span>
            </div>
            <div class="mt-2 flex gap-2 text-xs">
              <label class="cursor-pointer font-semibold text-indigo-600 hover:text-indigo-700">
                {{ imageBusy === 'logo' ? 'Working…' : 'Upload' }}
                <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="uploadImage('logo', $event)" />
              </label>
              <button v-if="logoUrl" type="button" class="text-rose-600 hover:text-rose-700" @click="removeImage('logo')">
                Remove
              </button>
            </div>
          </div>

          <!-- Cover -->
          <div>
            <span class="mb-2 block text-xs font-medium text-slate-600">Cover image</span>
            <div class="flex h-32 items-center justify-center overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200">
              <img v-if="coverUrl" :src="coverUrl" alt="Cover" class="h-full w-full object-cover" />
              <span v-else class="text-xs text-slate-400">No cover image</span>
            </div>
            <div class="mt-2 flex gap-2 text-xs">
              <label class="cursor-pointer font-semibold text-indigo-600 hover:text-indigo-700">
                {{ imageBusy === 'cover' ? 'Working…' : 'Upload' }}
                <input ref="coverInput" type="file" accept="image/*" class="hidden" @change="uploadImage('cover', $event)" />
              </label>
              <button v-if="coverUrl" type="button" class="text-rose-600 hover:text-rose-700" @click="removeImage('cover')">
                Remove
              </button>
            </div>
          </div>
        </div>

        <div class="mt-6">
          <span class="sh-label">Theme colour</span>
          <p class="mb-3 text-xs text-ink/55">
            Accents your dashboard and your public booking pages.
          </p>

          <div class="flex flex-wrap items-center gap-2.5">
            <button
              v-for="hex in THEME_SWATCHES"
              :key="hex"
              data-swatch
              type="button"
              class="h-9 w-9 rounded-full ring-2 ring-offset-2 transition"
              :style="{ backgroundColor: hex }"
              :class="form.theme_color === hex ? 'ring-ink' : 'ring-transparent hover:ring-ink/20'"
              :aria-label="hex"
              :aria-pressed="form.theme_color === hex"
              @click="chooseTheme(hex)"
            />

            <span class="ml-2 h-6 w-px bg-ink/10"></span>

            <label class="flex items-center gap-2 text-xs font-medium text-ink/60">
              Custom
              <input
                :value="form.theme_color"
                type="color"
                class="h-9 w-12 cursor-pointer rounded-lg border border-ink/15 bg-white p-1"
                @input="chooseTheme($event.target.value)"
              />
            </label>

            <input
              :value="form.theme_color"
              type="text"
              class="sh-input w-32 font-mono text-sm uppercase"
              @change="chooseTheme($event.target.value)"
            />
          </div>

          <p v-if="fieldError('theme_color')" class="sh-error">{{ fieldError('theme_color') }}</p>
        </div>
      </section>

      <form class="space-y-6" @submit.prevent="save">
        <!-- Details -->
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">Salon details</h2>

          <div>
            <label class="mb-1 block text-sm font-medium text-slate-800">Name</label>
            <input
              v-model="form.name"
              type="text"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
            />
            <p v-if="fieldError('name')" class="mt-1 text-xs text-red-600">{{ fieldError('name') }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Email</label>
              <input
                v-model="form.email"
                type="email"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
              />
              <p v-if="fieldError('email')" class="mt-1 text-xs text-red-600">{{ fieldError('email') }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Phone</label>
              <input
                v-model="form.phone"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
              />
              <p v-if="fieldError('phone')" class="mt-1 text-xs text-red-600">{{ fieldError('phone') }}</p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Country</label>
              <input
                v-model="form.country"
                type="text"
                maxlength="2"
                placeholder="US"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase focus:border-indigo-500 focus:outline-none"
              />
              <p v-if="fieldError('country')" class="mt-1 text-xs text-red-600">{{ fieldError('country') }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Currency</label>
              <input
                v-model="form.currency"
                type="text"
                maxlength="3"
                placeholder="USD"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase focus:border-indigo-500 focus:outline-none"
              />
              <p v-if="fieldError('currency')" class="mt-1 text-xs text-red-600">{{ fieldError('currency') }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Timezone</label>
              <select
                v-if="timezones.length"
                v-model="form.timezone"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
              >
                <option value="">Select…</option>
                <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
              </select>
              <input
                v-else
                v-model="form.timezone"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
              />
              <p v-if="fieldError('timezone')" class="mt-1 text-xs text-red-600">{{ fieldError('timezone') }}</p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Public page</label>
              <input
                :value="siteUrl"
                type="text"
                readonly
                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500"
              />
              <a :href="siteUrl" target="_blank" rel="noopener" class="mt-1 inline-block text-xs font-medium text-indigo-600">
                Open ↗
              </a>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-slate-800">Booking link</label>
              <input
                :value="bookingUrl"
                type="text"
                readonly
                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500"
              />
              <p class="mt-1 text-xs text-slate-500">Fixed — every link you have shared points here.</p>
            </div>
          </div>
        </section>

        <!-- Story + social -->
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">About &amp; social</h2>

          <div>
            <label class="mb-1 block text-sm font-medium text-slate-800">About</label>
            <textarea
              v-model="form.about"
              rows="4"
              placeholder="A couple of lines about your salon, shown on your public page."
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
            />
            <p v-if="fieldError('about')" class="mt-1 text-xs text-red-600">{{ fieldError('about') }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div v-for="key in ['facebook', 'instagram', 'website']" :key="key">
              <label class="mb-1 block text-sm font-medium text-slate-800 capitalize">{{ key }}</label>
              <input
                v-model="form[key]"
                type="url"
                placeholder="https://"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
              />
              <p v-if="fieldError(key)" class="mt-1 text-xs text-red-600">{{ fieldError(key) }}</p>
            </div>
          </div>
        </section>

        <p v-if="formMessage" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ formMessage }}</p>
        <p v-if="savedOk" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">Salon profile saved.</p>

        <div class="flex justify-end">
          <button
            type="submit"
            :disabled="saving"
            class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60"
          >
            {{ saving ? 'Saving…' : 'Save profile' }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>
