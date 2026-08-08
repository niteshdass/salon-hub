<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const emit = defineEmits(['done', 'skip', 'back'])
const authStore = useAuthStore()

const presets = ref([])
const chosenType = ref(null)
const rows = ref([])
const saving = ref(false)
const error = ref('')
const rowErrors = ref({})

const currency = computed(() => authStore.organization?.currency || 'USD')

onMounted(async () => {
  try {
    const { data } = await api.get('/service-presets')
    presets.value = data.data
  } catch (err) {
    error.value = parseApiError(err).message
  }
})

// Picking a type fills the list with that menu, everything ticked. The
// owner unticks what they do not do — reading and unticking is far less
// work for a non-technical user than composing a menu from nothing.
function chooseType(type) {
  chosenType.value = type
  rows.value = type.services.map((service) => ({
    name: service.name,
    duration: service.duration,
    price: '',
    ticked: true,
  }))
  rowErrors.value = {}
  error.value = ''
}

// "Change" backs out of the chosen menu entirely — any error from a
// previous save attempt describes rows that no longer exist, so it must
// not linger onto whatever the owner picks next.
function changeType() {
  chosenType.value = null
  rowErrors.value = {}
  error.value = ''
}

function addOwnRow() {
  rows.value.push({ name: '', duration: 30, price: '', ticked: true })
}

// A price the server will accept: present, a finite number, never negative
// (server rule is `numeric|min:0`). Checked client-side so a bad value is
// caught before the round trip, not after.
function isValidPrice(price) {
  const n = Number(price)
  return String(price).trim() !== '' && Number.isFinite(n) && n >= 0
}

// A duration the server will accept: a whole number of minutes, at least
// one (server rule is `integer|min:1`). Blanking the field leaves an empty
// string; Vue's `.number` modifier can't parse that so it stays a string,
// and `Number('')` is 0 — which must read as invalid, not "free".
function isValidDuration(duration) {
  const n = Number(duration)
  return Number.isInteger(n) && n >= 1
}

const ticked = computed(() => rows.value.filter((row) => row.ticked))
const canSave = computed(
  () =>
    ticked.value.length > 0 &&
    ticked.value.every((row) => row.name.trim() && isValidDuration(row.duration) && isValidPrice(row.price)),
)

const blockingReason = computed(() => {
  if (!chosenType.value) return 'Pick your salon type to continue.'
  if (ticked.value.length === 0) return 'Tick at least one service.'
  if (ticked.value.some((row) => !isValidDuration(row.duration))) {
    return 'Set a duration of at least 1 minute for every service you ticked.'
  }
  if (ticked.value.some((row) => String(row.price).trim() === '')) {
    return 'Add a price for every service you ticked.'
  }
  if (ticked.value.some((row) => !isValidPrice(row.price))) {
    return 'Enter a price of 0 or more for every service you ticked.'
  }
  // Only a blank service name can still fail canSave at this point — the
  // catch-all keeps Continue from ever being disabled with nothing said.
  if (!canSave.value) return 'Add a price for every service you ticked.'
  return ''
})

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  rowErrors.value = {}
  // Build the posted list and, in the same pass, a map from each posted
  // row's position back to its position in the full `rows` array (the one
  // the template renders and `rowErrors` is keyed against). Ticking a row
  // out shifts every later row's position in the posted array but not in
  // `rows`, so the two only agree by coincidence — never assume they match.
  const postedRowIndexes = []
  const postedRows = []
  rows.value.forEach((row, index) => {
    if (!row.ticked) return
    postedRowIndexes.push(index)
    postedRows.push({
      name: row.name.trim(),
      duration: Number(row.duration),
      price: Number(row.price),
    })
  })
  try {
    await api.post('/services/bulk', {
      category: chosenType.value.label,
      rows: postedRows,
    })
    emit('done')
  } catch (err) {
    const parsed = parseApiError(err)
    error.value = parsed.message
    // Errors arrive keyed `rows.<postedIndex>.<field>`, e.g. `rows.1.price`.
    // Translate the posted index back through postedRowIndexes to the row's
    // real position before highlighting it.
    for (const [key, messages] of Object.entries(parsed.errors ?? {})) {
      const match = key.match(/^rows\.(\d+)\./)
      if (!match) continue
      const rowIndex = postedRowIndexes[Number(match[1])]
      if (rowIndex !== undefined) rowErrors.value[rowIndex] = messages[0]
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="2"
    title="What do you offer?"
    subtitle="Pick your salon type, then set your prices. You can change all of this later."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div v-if="!chosenType" class="grid gap-3 sm:grid-cols-2">
      <button
        v-for="type in presets"
        :key="type.key"
        type="button"
        class="sh-card p-5 text-left transition hover:border-accent-300"
        @click="chooseType(type)"
      >
        <span class="block font-semibold text-ink">{{ type.label }}</span>
        <span class="mt-1 block text-sm text-ink/60">{{ type.services.length }} popular services ready to go</span>
      </button>
    </div>

    <div v-else class="sh-card p-5">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold text-ink">{{ chosenType.label }}</h2>
        <button type="button" class="sh-btn sh-btn-ghost px-2.5 py-1 text-xs" @click="changeType">Change</button>
      </div>

      <ul class="mt-4 space-y-3">
        <li
          v-for="(row, i) in rows"
          :key="i"
          class="rounded-xl p-3 ring-1"
          :class="rowErrors[i] ? 'ring-rose-300 bg-rose-50' : 'ring-ink/10'"
        >
          <div class="flex items-center gap-3">
            <input v-model="row.ticked" type="checkbox" class="h-5 w-5 rounded border-ink/20 text-accent-600 focus:ring-accent-300" />
            <input
              v-model="row.name"
              type="text"
              placeholder="Service name"
              class="sh-input min-w-0 flex-1"
            />
          </div>
          <div v-if="row.ticked" class="mt-2 flex items-center gap-3 pl-8">
            <label class="flex items-center gap-1.5 text-sm text-ink/60">
              <input v-model.number="row.duration" type="number" min="5" step="5" class="sh-input w-20 px-2 py-1.5" />
              min
            </label>
            <label class="flex items-center gap-1.5 text-sm text-ink/60">
              <span>{{ currency }}</span>
              <input
                v-model="row.price"
                type="number"
                min="0"
                step="any"
                placeholder="Price"
                class="sh-input w-28 px-2 py-1.5"
              />
            </label>
          </div>
          <p v-if="rowErrors[i]" class="mt-1 pl-8 text-sm text-rose-600">{{ rowErrors[i] }}</p>
        </li>
      </ul>

      <button type="button" class="sh-btn sh-btn-ghost mt-4 px-2.5 py-1 text-xs" @click="addOwnRow">
        + Add your own
      </button>
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
      <p v-if="blockingReason" class="mt-2 text-center text-sm text-ink/60">{{ blockingReason }}</p>
    </template>
  </OnboardingLayout>
</template>
