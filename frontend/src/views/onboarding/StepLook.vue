<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { THEME_SWATCHES } from '@/lib/theme'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const emit = defineEmits(['done', 'skip', 'back'])
const authStore = useAuthStore()

const about = ref('')
const themeColor = ref(THEME_SWATCHES[0])
const logoUrl = ref(null)
const logoInput = ref(null)
const saving = ref(false)
const uploading = ref(false)
const removing = ref(false)
const error = ref('')

const logoBusy = computed(() => uploading.value || removing.value)

const salonName = computed(() => authStore.organization?.name ?? 'Your salon')

onMounted(async () => {
  try {
    const { data } = await api.get('/settings/organization')
    about.value = data.data.about ?? ''
    themeColor.value = data.data.theme_color || THEME_SWATCHES[0]
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
    // Clear the input's value or picking the same file again after a
    // failed upload fires no 'change' event and looks like a dead button.
    if (logoInput.value) logoInput.value.value = ''
  }
}

async function removeLogo() {
  removing.value = true
  error.value = ''
  try {
    await api.delete('/settings/organization/logo')
    logoUrl.value = null
  } catch (err) {
    error.value = parseApiError(err).message
  } finally {
    removing.value = false
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
    // OnboardingStatus derives this step as done from
    // filled(about) || filled(logo) — a colour-only save satisfies
    // neither, so emitting 'done' here would tell the owner the step is
    // finished only for the next status fetch to quietly disagree. Advance
    // either way (this step is optional and a colour-only save is a
    // perfectly reasonable thing to do) but only mark it done when the
    // server would actually agree it is.
    if (about.value.trim() || logoUrl.value) {
      emit('done')
    } else {
      emit('skip')
    }
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
      <div class="sh-card space-y-4 p-5">
        <div>
          <span class="sh-label">Your logo</span>
          <div class="flex items-center gap-3">
            <div
              class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-ink/5 ring-1 ring-ink/10"
            >
              <img
                v-if="logoUrl"
                :src="logoUrl"
                alt="Your logo"
                data-test="logo-thumbnail"
                class="h-full w-full object-cover"
              />
              <span v-else data-test="logo-empty" class="px-1 text-center text-[11px] leading-tight text-ink/40">
                No logo
              </span>
            </div>

            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <!-- sr-only, not hidden: the input stays in the tab order so
                     the styled label is reachable by keyboard, and 'peer'
                     paints its focus ring onto the label. -->
                <input
                  id="onboarding-logo"
                  ref="logoInput"
                  type="file"
                  accept="image/*"
                  class="peer sr-only"
                  :disabled="logoBusy"
                  @change="uploadLogo"
                />
                <label
                  for="onboarding-logo"
                  class="sh-btn cursor-pointer px-2.5 py-1 text-xs peer-focus-visible:ring-2 peer-focus-visible:ring-accent-400 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed peer-disabled:opacity-60"
                >
                  {{ uploading ? 'Uploading…' : logoUrl ? 'Change logo' : 'Upload logo' }}
                </label>
                <button
                  v-if="logoUrl"
                  type="button"
                  data-test="logo-remove"
                  :disabled="logoBusy"
                  class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                  @click="removeLogo"
                >
                  {{ removing ? 'Removing…' : 'Remove' }}
                </button>
              </div>
              <p class="mt-1.5 text-xs text-ink/60">PNG or JPG. A square image looks best.</p>
            </div>
          </div>
        </div>

        <div>
          <label class="sh-label">A line about your salon</label>
          <textarea
            v-model="about"
            rows="4"
            maxlength="5000"
            placeholder="We have cut hair on this street since 1998."
            class="sh-input"
          />
        </div>

        <div>
          <label class="sh-label">Colour</label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="colour in THEME_SWATCHES"
              :key="colour"
              type="button"
              class="h-9 w-9 rounded-full ring-2 ring-offset-2 transition"
              :style="{ backgroundColor: colour }"
              :class="themeColor === colour ? 'ring-ink' : 'ring-transparent'"
              :aria-label="colour"
              @click="themeColor = colour"
            />
          </div>
        </div>
      </div>

      <div class="sh-card p-5">
        <p class="text-xs font-medium uppercase tracking-wide text-ink/40">Preview</p>
        <div class="mt-3 overflow-hidden rounded-xl ring-1 ring-ink/10">
          <div class="h-20" :style="{ backgroundColor: themeColor }" />
          <div class="p-4">
            <img v-if="logoUrl" :src="logoUrl" alt="" class="h-12 w-12 rounded-full object-cover ring-2 ring-white" />
            <p class="mt-2 font-semibold text-ink">{{ salonName }}</p>
            <p class="mt-1 text-sm text-ink/60">{{ about || 'Your salon story goes here.' }}</p>
          </div>
        </div>
      </div>
    </div>

    <p v-if="error" class="sh-alert mt-4 border-rose-200 bg-rose-50 text-rose-700">{{ error }}</p>

    <template #action>
      <button
        type="button"
        :disabled="saving"
        class="sh-btn sh-btn-primary w-full py-3"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
    </template>
  </OnboardingLayout>
</template>
