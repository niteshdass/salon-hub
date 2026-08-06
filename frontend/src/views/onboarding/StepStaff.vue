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
const error = ref('')

onMounted(async () => {
  try {
    const [serviceRes, branchRes] = await Promise.all([
      api.get('/services'),
      props.branchId ? api.get(`/branches/${props.branchId}`) : Promise.resolve(null),
    ])
    services.value = serviceRes.data.data
    branchHours.value = branchRes?.data?.data?.opening_hours_json ?? null
  } catch (err) {
    error.value = parseApiError(err).message
  }
})

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
    <div v-if="!mode" class="grid gap-3 sm:grid-cols-2">
      <button
        type="button"
        class="rounded-2xl bg-white p-6 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-indigo-400"
        @click="chooseSolo"
      >
        <span class="block text-lg font-semibold text-slate-900">I work alone</span>
        <span class="mt-1 block text-sm text-slate-500">We'll set you up as the only person customers can book.</span>
      </button>
      <button
        type="button"
        class="rounded-2xl bg-white p-6 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-indigo-400"
        @click="chooseTeam"
      >
        <span class="block text-lg font-semibold text-slate-900">I have a team</span>
        <span class="mt-1 block text-sm text-slate-500">Add each person and what they do.</span>
      </button>
    </div>

    <div v-else-if="mode === 'team'" class="space-y-4">
      <div v-for="(person, i) in people" :key="i" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1 space-y-3">
            <input
              v-model="person.name"
              type="text"
              placeholder="Name"
              class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            />
            <div class="grid gap-3 sm:grid-cols-2">
              <input
                v-model="person.phone"
                type="tel"
                placeholder="Phone (optional)"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <input
                v-model="person.email"
                type="email"
                placeholder="Email — only if they should log in"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
            </div>
          </div>
          <button v-if="people.length > 1" type="button" class="text-sm text-slate-400 hover:text-rose-600" @click="removePerson(i)">
            Remove
          </button>
        </div>

        <p class="mt-4 text-sm font-medium text-slate-700">What do they do?</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="service in services"
            :key="service.id"
            type="button"
            class="rounded-full px-3 py-1.5 text-sm ring-1 transition"
            :class="person.serviceIds.includes(service.id)
              ? 'bg-indigo-600 text-white ring-indigo-600'
              : 'bg-white text-slate-600 ring-slate-300'"
            @click="toggleService(person, service.id)"
          >
            {{ service.name }}
          </button>
        </div>
      </div>

      <button
        type="button"
        :disabled="atLimit"
        class="text-sm font-medium text-indigo-600 disabled:text-slate-400"
        @click="addPerson"
      >
        + Add another person
      </button>
      <p v-if="atLimit" class="text-sm text-slate-500">
        Your free plan covers {{ FREE_MAX_STAFF }} people. Upgrade later to add more.
      </p>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template #action>
      <button
        v-if="mode === 'team'"
        type="button"
        :disabled="!canSave || saving"
        class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
      <p v-else-if="saving" class="text-center text-sm text-slate-500">Setting you up…</p>
      <p v-if="mode === 'team' && !saving && continueBlockedReason" class="mt-2 text-center text-sm text-slate-500">
        {{ continueBlockedReason }}
      </p>
    </template>
  </OnboardingLayout>
</template>
