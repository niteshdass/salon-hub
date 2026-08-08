<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const props = defineProps({ branchId: { type: Number, default: null } })
const emit = defineEmits(['done', 'skip', 'back'])

const authStore = useAuthStore()

// Mirrors PlanLimit::FREE_MAX_STAFF. The server is the gate; this only
// keeps the owner from typing an eleventh row it would refuse.
const FREE_MAX_STAFF = 10

// working_days_json is 1..7 (Monday..Sunday); the branch stores hours by
// three-letter key. This maps one onto the other.
const DAY_NUMBERS = { mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6, sun: 7 }

const mode = ref(null) // null | 'solo' | 'team'
const services = ref([])
const branchHours = ref(null)
const people = ref([])
const saving = ref(false)
const loading = ref(true)
const error = ref('')

// The services step is REQUIRED and runs before this screen, so by the time
// the owner reaches here at least one service exists server-side. An empty
// list here therefore never means "this salon truly has no services" — it
// means the fetch failed. That distinction matters: Public\BookingController
// only falls back to "show every staff member" while NO service anywhere
// has a staff assignment. A person created here with service_ids: [] looks
// fine right up until the owner links any other staff member to any other
// service, at which point this person silently drops out of every service
// they were never explicitly linked to. So neither entry path may open
// until services have actually loaded.
const servicesFailed = computed(() => !loading.value && services.value.length === 0)

async function loadServices() {
  try {
    const { data } = await api.get('/services')
    services.value = data.data
  } catch {
    services.value = []
  }
}

async function loadBranchHours() {
  if (!props.branchId) return
  try {
    const { data } = await api.get(`/branches/${props.branchId}`)
    branchHours.value = data?.data?.opening_hours_json ?? null
  } catch {
    // Non-blocking: a branch that can't be read just falls back to the
    // Mon-Sat 09:00-18:00 default below, same as StepBranch does for its
    // own fetch failure. Only a failed *services* fetch stops the owner.
  }
}

async function loadEverything() {
  loading.value = true
  await Promise.all([loadServices(), loadBranchHours()])
  loading.value = false
}

onMounted(loadEverything)

const allServiceIds = computed(() => services.value.map((s) => s.id))

// Working days and hours copied off the branch: a salon that opens Mon-Sat
// 9-6 almost always has staff who work those hours, and asking again is a
// question with an obvious answer.
const workingDays = computed(() => {
  if (!branchHours.value) return [1, 2, 3, 4, 5, 6]
  return Object.entries(branchHours.value)
    .filter(([, value]) => Array.isArray(value))
    .map(([key]) => DAY_NUMBERS[key])
    .filter(Boolean)
    .sort((a, b) => a - b)
})

const workingHours = computed(() => {
  const open = Object.values(branchHours.value ?? {}).find((value) => Array.isArray(value))
  return open ? { start: open[0], end: open[1] } : { start: '09:00', end: '18:00' }
})

function chooseSolo() {
  mode.value = 'solo'
  people.value = [
    { name: authStore.user?.name ?? authStore.organization?.name ?? 'Me', phone: '', email: '', serviceIds: [...allServiceIds.value] },
  ]
  save()
}

function chooseTeam() {
  mode.value = 'team'
  people.value = [
    { name: authStore.user?.name ?? '', phone: '', email: '', serviceIds: [...allServiceIds.value] },
  ]
}

const atLimit = computed(() => people.value.length >= FREE_MAX_STAFF)

function addPerson() {
  if (atLimit.value) return
  people.value.push({ name: '', phone: '', email: '', serviceIds: [...allServiceIds.value] })
}

function removePerson(index) {
  people.value.splice(index, 1)
}

function toggleService(person, id) {
  const at = person.serviceIds.indexOf(id)
  if (at === -1) person.serviceIds.push(id)
  else person.serviceIds.splice(at, 1)
}

const canSave = computed(() => people.value.length > 0 && people.value.every((p) => p.name.trim()))

// OnboardingLayout renders the #action slot unconditionally, so a disabled
// Continue button must always say why — an unexplained disabled button
// leaves a non-technical owner stuck with no next move.
const continueBlockedReason = computed(() => {
  if (people.value.length === 0) return 'Add at least one person to continue.'
  if (people.value.some((p) => !p.name.trim())) return 'Add a name for each person before continuing.'
  return ''
})

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  try {
    // Sequential, not parallel: the plan limit is counted per request on
    // the server, and ten simultaneous creates could each see a count of
    // nine. One at a time also means the first refusal stops the rest.
    for (const person of people.value) {
      await api.post('/staff', {
        name: person.name.trim(),
        phone: person.phone.trim() || null,
        email: person.email.trim() || null,
        service_ids: person.serviceIds,
        working_days_json: workingDays.value,
        working_hours_json: workingHours.value,
      })
    }
    emit('done')
  } catch (err) {
    error.value = parseApiError(err).message
    // Back to the form so the owner can fix the row rather than lose the
    // list; the ones already created stay created, which the next
    // fetchStatus reflects honestly.
    mode.value = 'team'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="3"
    title="Who works here?"
    subtitle="Customers pick a person when they book."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div v-if="loading" class="sh-card p-6 text-center text-sm text-ink/60">
      Loading your services…
    </div>

    <div v-else-if="servicesFailed" class="sh-card p-6">
      <p class="text-sm text-ink/60">
        We couldn't load your services just now, so we can't show what each person can do yet.
      </p>
      <button
        type="button"
        class="sh-btn sh-btn-ghost mt-3 px-2.5 py-1 text-xs"
        @click="loadEverything"
      >
        Try again
      </button>
    </div>

    <div v-else-if="!mode" class="grid gap-3 sm:grid-cols-2">
      <button
        type="button"
        class="sh-card p-6 text-left transition hover:border-accent-300"
        @click="chooseSolo"
      >
        <span class="block text-lg font-semibold text-ink">I work alone</span>
        <span class="mt-1 block text-sm text-ink/60">We'll set you up as the only person customers can book.</span>
      </button>
      <button
        type="button"
        class="sh-card p-6 text-left transition hover:border-accent-300"
        @click="chooseTeam"
      >
        <span class="block text-lg font-semibold text-ink">I have a team</span>
        <span class="mt-1 block text-sm text-ink/60">Add each person and what they do.</span>
      </button>
    </div>

    <div v-else-if="mode === 'team'" class="space-y-4">
      <div v-for="(person, i) in people" :key="i" class="sh-card p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1 space-y-3">
            <input
              v-model="person.name"
              type="text"
              placeholder="Name"
              class="sh-input"
            />
            <div class="grid gap-3 sm:grid-cols-2">
              <input
                v-model="person.phone"
                type="tel"
                placeholder="Phone (optional)"
                class="sh-input"
              />
              <input
                v-model="person.email"
                type="email"
                placeholder="Email — only if they should log in"
                class="sh-input"
              />
            </div>
          </div>
          <button v-if="people.length > 1" type="button" class="text-sm text-ink/40 hover:text-rose-600" @click="removePerson(i)">
            Remove
          </button>
        </div>

        <p class="mt-4 text-sm font-medium text-ink/75">What do they do?</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="service in services"
            :key="service.id"
            type="button"
            class="rounded-full px-3 py-1.5 text-sm ring-1 transition"
            :class="person.serviceIds.includes(service.id)
              ? 'bg-accent-500 text-accent-fg ring-accent-500'
              : 'bg-white text-ink/60 ring-ink/15'"
            @click="toggleService(person, service.id)"
          >
            {{ service.name }}
          </button>
        </div>
      </div>

      <button
        type="button"
        :disabled="atLimit"
        class="sh-btn sh-btn-ghost px-2.5 py-1 text-xs"
        @click="addPerson"
      >
        + Add another person
      </button>
      <p v-if="atLimit" class="text-sm text-ink/60">
        Your free plan covers {{ FREE_MAX_STAFF }} people. Upgrade later to add more.
      </p>
    </div>

    <p v-if="error" class="sh-alert mt-4 border-rose-200 bg-rose-50 text-rose-700">{{ error }}</p>

    <template #action>
      <button
        v-if="mode === 'team'"
        type="button"
        :disabled="!canSave || saving"
        class="sh-btn sh-btn-primary w-full py-3"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
      <p v-else-if="saving" class="text-center text-sm text-ink/60">Setting you up…</p>
      <p v-if="mode === 'team' && !saving && continueBlockedReason" class="mt-2 text-center text-sm text-ink/60">
        {{ continueBlockedReason }}
      </p>
    </template>
  </OnboardingLayout>
</template>
