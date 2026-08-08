<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const props = defineProps({ branchId: { type: Number, default: null } })
const emit = defineEmits(['done', 'skip', 'back'])

const authStore = useAuthStore()

// Lowercase three-letter keys, exactly what SlotGenerator reads out of
// branches.opening_hours_json.
const DAYS = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
]

const form = ref({ address: '', city: '', phone: '' })
const hours = ref(
  Object.fromEntries(
    DAYS.map((d) => [d.key, { open: d.key === 'sun' ? false : true, from: '09:00', to: '18:00' }]),
  ),
)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

onMounted(async () => {
  form.value.phone = authStore.organization?.phone ?? ''
  if (!props.branchId) return
  try {
    const { data } = await api.get(`/branches/${props.branchId}`)
    const branch = data.data
    form.value.address = branch.address ?? ''
    form.value.city = branch.city ?? ''
    form.value.phone = branch.phone ?? form.value.phone
    for (const day of DAYS) {
      const stored = branch.opening_hours_json?.[day.key]
      hours.value[day.key] = stored
        ? { open: true, from: stored[0], to: stored[1] }
        : { open: false, from: '09:00', to: '18:00' }
    }
  } catch {
    // A branch we cannot read is not worth blocking setup over — the
    // defaults above are the same ones registration wrote.
  }
})

// One tap to say "we open the same time every day": copy Monday down onto
// every day that is open. Days marked closed stay closed.
function copyMondayDown() {
  const monday = hours.value.mon
  for (const day of DAYS) {
    if (day.key === 'mon') continue
    if (!hours.value[day.key].open) continue
    hours.value[day.key] = { ...hours.value[day.key], from: monday.from, to: monday.to }
  }
}

// `branchId` is part of what makes saving possible, not a detail the button
// may quietly swallow: without it there is no branch to PUT to. Leaving it
// out of `canSave` is what let Continue render enabled and then refuse every
// click in silence — on the very first screen of the product.
const canSave = computed(() => !!props.branchId && form.value.address.trim().length > 0)

// A disabled Continue must always say why, in words an owner can act on.
const blockedReason = computed(() => {
  if (!props.branchId) {
    return "We couldn't find your salon's location, so there's nothing to save this to yet. Please reload the page and try again."
  }
  if (!form.value.address.trim()) return 'Add your address to continue.'
  return ''
})

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    await api.put(`/branches/${props.branchId}`, {
      address: form.value.address.trim(),
      city: form.value.city.trim() || null,
      phone: form.value.phone.trim() || null,
      opening_hours_json: Object.fromEntries(
        DAYS.map(({ key }) => [key, hours.value[key].open ? [hours.value[key].from, hours.value[key].to] : null]),
      ),
    })
    emit('done')
  } catch (err) {
    const parsed = parseApiError(err)
    error.value = parsed.message
    fieldErrors.value = parsed.errors ?? {}
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="1"
    title="Where is your salon?"
    subtitle="Four quick steps. About 3 minutes. Customers see this address on your booking page."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div class="sh-card space-y-4 p-5">
      <div>
        <label class="sh-label">Street address</label>
        <input
          v-model="form.address"
          type="text"
          placeholder="12 Green Road, Dhanmondi"
          class="sh-input"
        />
        <p v-if="fieldErrors.address" class="sh-error">{{ fieldErrors.address[0] }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="sh-label">City</label>
          <input
            v-model="form.city"
            type="text"
            class="sh-input"
          />
        </div>
        <div>
          <label class="sh-label">Phone</label>
          <input
            v-model="form.phone"
            type="tel"
            class="sh-input"
          />
        </div>
      </div>
    </div>

    <div class="sh-card mt-5 p-5">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold text-ink">When are you open?</h2>
        <button type="button" class="sh-btn sh-btn-ghost px-2.5 py-1 text-xs" @click="copyMondayDown">
          Same time every day
        </button>
      </div>

      <ul class="mt-4 divide-y divide-ink/10">
        <li v-for="day in DAYS" :key="day.key" class="flex flex-wrap items-center gap-3 py-2.5">
          <label class="flex min-w-32 items-center gap-2">
            <input v-model="hours[day.key].open" type="checkbox" class="h-4 w-4 rounded border-ink/20 text-accent-600 focus:ring-accent-300" />
            <span class="text-sm font-medium text-ink/75">{{ day.label }}</span>
          </label>
          <template v-if="hours[day.key].open">
            <input v-model="hours[day.key].from" type="time" class="sh-input w-auto px-2 py-1.5 text-sm" />
            <span class="text-ink/40">to</span>
            <input v-model="hours[day.key].to" type="time" class="sh-input w-auto px-2 py-1.5 text-sm" />
          </template>
          <span v-else class="text-sm text-ink/40">Closed</span>
        </li>
      </ul>
    </div>

    <p v-if="error" class="sh-alert mt-4 border-rose-200 bg-rose-50 text-rose-700">{{ error }}</p>

    <template #action>
      <button
        type="button"
        :disabled="!canSave || saving"
        class="sh-btn sh-btn-primary w-full py-3"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
      <p v-if="blockedReason" class="mt-2 text-center text-sm text-ink/60">{{ blockedReason }}</p>
    </template>
  </OnboardingLayout>
</template>
