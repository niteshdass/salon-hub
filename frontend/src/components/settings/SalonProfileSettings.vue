<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { BRAND_ACCENT, THEME_SWATCHES, normalizeAccent } from '@/lib/theme'
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

// The colour the server last confirmed, so an abandoned preview can be undone.
const savedThemeColor = ref(null)

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

/*
 * The column's default. It has to survive untouched: the public site, the
 * booking flow and the manage-booking page all read it as "this salon never
 * chose", and answer with their own gold. Writing a real colour over it here
 * would silently restyle those customer-facing pages for an owner who only
 * came in to edit a phone number. So the sentinel stays in the database and
 * we simply stop showing it as if it were a choice.
 */
const UNCHOSEN = '#6366f1'

const isUnchosen = computed(() => form.theme_color?.toLowerCase() === UNCHOSEN)

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

// The image endpoints return the whole organization, including the stored
// colour — landing that would undo a pick the owner has not saved yet.
function apply(data, { theme = true } = {}) {
  slug.value = data.slug || ''
  logoUrl.value = data.logo_url || null
  coverUrl.value = data.cover_image_url || null
  for (const key of Object.keys(form)) {
    if (key === 'theme_color' && !theme) continue
    form[key] = data[key] ?? (key === 'theme_color' ? UNCHOSEN : '')
  }
  if (theme) {
    savedThemeColor.value = form.theme_color
    themeStore.setAccent(form.theme_color)
  }
}

// Selecting a colour repaints the app at once — the owner judges it against
// the real sidebar rather than a swatch.
function chooseTheme(hex) {
  form.theme_color = hex
  themeStore.setAccent(hex)
}

/*
 * Typed hexes only settle on blur. Normalizing every keystroke would fight
 * someone half way through '#0f7'; leaving them alone entirely would send
 * whatever they typed to a server that rejects anything but six hex digits.
 */
function commitTheme(event) {
  // An emptied field is not a choice. normalizeAccent('') answers brand
  // terracotta, and committing that would write a real colour over the
  // sentinel — moving the salon's public page off its gold on the next save.
  if (event.target.value.trim() === '') {
    event.target.value = isUnchosen.value ? '' : form.theme_color
    return
  }

  chooseTheme(normalizeAccent(event.target.value))
  // If the junk normalized back to the colour already held, the bound value
  // never changed and Vue leaves the junk on screen. Put it right by hand.
  event.target.value = form.theme_color
}

// A preview the owner walked away from must not follow them around the app,
// nor sit in localStorage waiting to flash on the next load.
onUnmounted(() => {
  if (savedThemeColor.value !== null && form.theme_color !== savedThemeColor.value) {
    themeStore.setAccent(savedThemeColor.value)
  }
})

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
    apply(data.data || {}, { theme: false })
    if (kind === 'logo') {
      // The sidebar needs the new logo, but fetchMe also repaints the accent
      // from the stored organization — the same unsaved pick apply() just
      // protected. Put the preview back once the session has refreshed.
      await authStore.fetchMe().catch(() => {})
      themeStore.setAccent(form.theme_color)
    }
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
    apply(data.data || {}, { theme: false })
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
    <p v-if="loadError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </p>

    <div v-if="loading" class="h-64 animate-pulse rounded-2xl bg-ink/5" />

    <template v-else>
      <!-- Branding -->
      <section class="sh-card p-6">
        <h2 class="text-sm font-semibold text-ink">Branding</h2>
        <p class="mt-1 text-sm text-ink/60">Shown across your public page and booking flow.</p>

        <p
          v-if="imageError"
          class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
        >
          {{ imageError }}
        </p>

        <div class="mt-5 grid gap-5 sm:grid-cols-[10rem_1fr]">
          <!-- Logo -->
          <div>
            <span class="sh-label text-xs">Logo</span>
            <div class="flex h-32 w-32 items-center justify-center overflow-hidden rounded-2xl bg-ink/5 ring-1 ring-ink/10">
              <img v-if="logoUrl" :src="logoUrl" alt="Salon logo" class="h-full w-full object-cover" />
              <span v-else class="text-xs text-ink/40">No logo</span>
            </div>
            <div class="mt-2 flex gap-2">
              <label class="sh-btn sh-btn-ghost cursor-pointer px-2.5 py-1 text-xs">
                {{ imageBusy === 'logo' ? 'Working…' : 'Upload' }}
                <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="uploadImage('logo', $event)" />
              </label>
              <button
                v-if="logoUrl"
                type="button"
                class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                @click="removeImage('logo')"
              >
                Remove
              </button>
            </div>
          </div>

          <!-- Cover -->
          <div>
            <span class="sh-label text-xs">Cover image</span>
            <div class="flex h-32 items-center justify-center overflow-hidden rounded-2xl bg-ink/5 ring-1 ring-ink/10">
              <img v-if="coverUrl" :src="coverUrl" alt="Cover" class="h-full w-full object-cover" />
              <span v-else class="text-xs text-ink/40">No cover image</span>
            </div>
            <div class="mt-2 flex gap-2">
              <label class="sh-btn sh-btn-ghost cursor-pointer px-2.5 py-1 text-xs">
                {{ imageBusy === 'cover' ? 'Working…' : 'Upload' }}
                <input ref="coverInput" type="file" accept="image/*" class="hidden" @change="uploadImage('cover', $event)" />
              </label>
              <button
                v-if="coverUrl"
                type="button"
                class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                @click="removeImage('cover')"
              >
                Remove
              </button>
            </div>
          </div>
        </div>

        <div class="mt-6">
          <span class="sh-label">Theme colour</span>
          <p class="mb-3 text-xs text-ink/55">
            Pick a colour and it accents your dashboard, your public page and your booking flow.
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
                :value="isUnchosen ? BRAND_ACCENT : form.theme_color"
                data-theme-picker
                type="color"
                class="h-9 w-12 cursor-pointer rounded-lg border border-ink/15 bg-white p-1"
                @input="chooseTheme($event.target.value)"
              />
            </label>

            <input
              :value="isUnchosen ? '' : form.theme_color"
              data-theme-hex
              type="text"
              placeholder="Not set"
              class="sh-input w-32 font-mono text-sm uppercase"
              @change="commitTheme"
            />
          </div>

          <p v-if="isUnchosen" class="mt-3 text-xs text-ink/55">
            Not set — your dashboard uses SalonHub terracotta and your public page uses its own gold.
          </p>

          <p v-if="fieldError('theme_color')" class="sh-error">{{ fieldError('theme_color') }}</p>
        </div>
      </section>

      <form class="space-y-6" @submit.prevent="save">
        <!-- Details -->
        <section class="sh-card space-y-4 p-6">
          <h2 class="text-sm font-semibold text-ink">Salon details</h2>

          <div>
            <label class="sh-label">Name</label>
            <input v-model="form.name" type="text" class="sh-input" />
            <p v-if="fieldError('name')" class="sh-error">{{ fieldError('name') }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="sh-label">Email</label>
              <input v-model="form.email" type="email" class="sh-input" />
              <p v-if="fieldError('email')" class="sh-error">{{ fieldError('email') }}</p>
            </div>
            <div>
              <label class="sh-label">Phone</label>
              <input v-model="form.phone" type="text" class="sh-input" />
              <p v-if="fieldError('phone')" class="sh-error">{{ fieldError('phone') }}</p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="sh-label">Country</label>
              <input v-model="form.country" type="text" maxlength="2" placeholder="US" class="sh-input uppercase" />
              <p v-if="fieldError('country')" class="sh-error">{{ fieldError('country') }}</p>
            </div>
            <div>
              <label class="sh-label">Currency</label>
              <input v-model="form.currency" type="text" maxlength="3" placeholder="USD" class="sh-input uppercase" />
              <p v-if="fieldError('currency')" class="sh-error">{{ fieldError('currency') }}</p>
            </div>
            <div>
              <label class="sh-label">Timezone</label>
              <select v-if="timezones.length" v-model="form.timezone" class="sh-input">
                <option value="">Select…</option>
                <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
              </select>
              <input v-else v-model="form.timezone" type="text" class="sh-input" />
              <p v-if="fieldError('timezone')" class="sh-error">{{ fieldError('timezone') }}</p>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="sh-label">Public page</label>
              <input :value="siteUrl" type="text" readonly class="sh-input bg-paper text-ink/60" />
              <a
                :href="siteUrl"
                target="_blank"
                rel="noopener"
                class="mt-1 inline-block text-xs font-medium text-accent-600 hover:text-accent-700"
              >
                Open ↗
              </a>
            </div>
            <div>
              <label class="sh-label">Booking link</label>
              <input :value="bookingUrl" type="text" readonly class="sh-input bg-paper text-ink/60" />
              <p class="mt-1 text-xs text-ink/55">Fixed — every link you have shared points here.</p>
            </div>
          </div>
        </section>

        <!-- Story + social -->
        <section class="sh-card space-y-4 p-6">
          <h2 class="text-sm font-semibold text-ink">About &amp; social</h2>

          <div>
            <label class="sh-label">About</label>
            <textarea
              v-model="form.about"
              rows="4"
              placeholder="A couple of lines about your salon, shown on your public page."
              class="sh-input"
            />
            <p v-if="fieldError('about')" class="sh-error">{{ fieldError('about') }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div v-for="key in ['facebook', 'instagram', 'website']" :key="key">
              <label class="sh-label capitalize">{{ key }}</label>
              <input v-model="form[key]" type="url" placeholder="https://" class="sh-input" />
              <p v-if="fieldError(key)" class="sh-error">{{ fieldError(key) }}</p>
            </div>
          </div>
        </section>

        <p v-if="formMessage" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {{ formMessage }}
        </p>
        <p v-if="savedOk" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          Salon profile saved.
        </p>

        <div class="flex justify-end">
          <button type="submit" :disabled="saving" class="sh-btn sh-btn-primary">
            {{ saving ? 'Saving…' : 'Save profile' }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>
