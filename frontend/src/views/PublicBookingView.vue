<script setup>
import { computed, onMounted, reactive, ref, watch, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { publicApiBase } from '@/lib/tenantHost'

const route = useRoute()
const slug = route.params.slug
// This view's route carries a required `:slug`, so this is always the
// path-scoped base. It goes through publicApiBase so the prefix is decided in
// one place; the host-resolved group deliberately has no `manage/*` or bare
// organization endpoint, so these calls must stay path-scoped.
const apiBase = publicApiBase(route.params.slug)

/* ------------------------------ Helpers ------------------------------ */
// Local (not UTC) YYYY-MM-DD for the current day.
function todayStr() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

// Render a "YYYY-MM-DD" as a friendly local date (no timezone drift).
function formatDate(dateStr) {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-').map(Number)
  if (!y || !m || !d) return dateStr
  const date = new Date(y, m - 1, d)
  return date.toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  })
}

// Render "09:00" (24h) as "9:00 AM".
function formatTime(t) {
  if (!t) return ''
  const [h, min] = t.split(':').map(Number)
  if (Number.isNaN(h)) return t
  const period = h < 12 ? 'AM' : 'PM'
  const hour12 = h % 12 === 0 ? 12 : h % 12
  return `${hour12}:${String(min ?? 0).padStart(2, '0')} ${period}`
}

function formatPrice(value) {
  if (value === null || value === undefined || value === '') return ''
  const num = Number(value)
  return Number.isNaN(num) ? value : num.toFixed(2)
}

function initials(name) {
  return (name || '?').trim().charAt(0).toUpperCase()
}

// Heuristic: does a 422 message look like the slot was taken meanwhile?
function looksLikeSlotConflict(message) {
  return /taken|no longer|already booked|not available|unavailable|slot/i.test(message || '')
}

/* ------------------------------ Wizard ------------------------------ */
const STEPS = ['Service', 'Staff', 'Date & time', 'Your details']
const step = ref(1) // 1..4 wizard steps, 5 = success

/* ------------------------------ Salon ------------------------------ */
const salon = ref(null)
const notFound = ref(false)
const loadingSalon = ref(true)
const salonError = ref('')

const primaryBranch = computed(() => salon.value?.branches?.[0] || null)

async function loadSalon() {
  loadingSalon.value = true
  salonError.value = ''
  notFound.value = false
  try {
    const { data } = await api.get(apiBase)
    salon.value = data.data
  } catch (err) {
    if (err?.response?.status === 404) {
      notFound.value = true
    } else {
      salonError.value = parseApiError(err, 'Could not load this salon.').message
    }
  } finally {
    loadingSalon.value = false
  }
}

/* ------------------------------ Step 1: Services ------------------------------ */
const services = ref([])
const servicesLoading = ref(false)
const servicesError = ref('')
const selectedService = ref(null)

async function loadServices() {
  servicesLoading.value = true
  servicesError.value = ''
  try {
    const { data } = await api.get(`${apiBase}/services`)
    services.value = data.data || []
  } catch (err) {
    servicesError.value = parseApiError(err, 'Could not load services.').message
  } finally {
    servicesLoading.value = false
  }
}

function selectService(svc) {
  const changed = selectedService.value?.id !== svc.id
  selectedService.value = svc
  if (changed) {
    // Reset everything downstream when the service changes.
    selectedStaff.value = null
    staff.value = []
    selectedSlot.value = ''
    slots.value = []
  }
  step.value = 2
  loadStaff()
}

/* ------------------------------ Step 2: Staff ------------------------------ */
const staff = ref([])
const staffLoading = ref(false)
const staffError = ref('')
const selectedStaff = ref(null)

async function loadStaff() {
  if (!selectedService.value) return
  staffLoading.value = true
  staffError.value = ''
  try {
    const { data } = await api.get(`${apiBase}/services/${selectedService.value.id}/staff`)
    staff.value = data.data || []
  } catch (err) {
    staffError.value = parseApiError(err, 'Could not load staff.').message
  } finally {
    staffLoading.value = false
  }
}

function selectStaff(member) {
  const changed = selectedStaff.value?.id !== member.id
  selectedStaff.value = member
  if (changed) {
    selectedSlot.value = ''
    slots.value = []
  }
  step.value = 3
  loadSlots()
}

/* ------------------------------ Step 3: Date & time ------------------------------ */
const selectedDate = ref(todayStr())
const slots = ref([])
const slotsLoading = ref(false)
const slotsError = ref('')
const slotsLoaded = ref(false)
const selectedSlot = ref('')

async function loadSlots() {
  if (!selectedService.value || !selectedStaff.value || !selectedDate.value) return
  slotsLoading.value = true
  slotsError.value = ''
  selectedSlot.value = ''
  try {
    const { data } = await api.get(`${apiBase}/slots`, {
      params: {
        service_id: selectedService.value.id,
        staff_id: selectedStaff.value.id,
        date: selectedDate.value,
      },
    })
    slots.value = data.data?.slots || []
  } catch (err) {
    slotsError.value = parseApiError(err, 'Could not load available times.').message
    slots.value = []
  } finally {
    slotsLoaded.value = true
    slotsLoading.value = false
  }
}

// Reload slots whenever the date changes while on the time step.
watch(selectedDate, () => {
  if (step.value >= 3) loadSlots()
})

function selectSlot(slot) {
  selectedSlot.value = slot
  step.value = 4
}

/* ------------------------------ Deposit ------------------------------ */
// The salon's deposit policy, surfaced on the public profile. A deposit is
// collectable when the salon wants one and offers a way to pay it — manual
// transfer, the online gateway, or both. The amount shown is computed
// client-side but the server is the authority on what is actually required.
const paymentPolicy = computed(() => salon.value?.payment || null)
const manualEnabled = computed(
  () => !!(paymentPolicy.value?.requires_deposit && paymentPolicy.value?.manual?.enabled),
)
const gatewayEnabled = computed(
  () => !!(paymentPolicy.value?.requires_deposit && paymentPolicy.value?.gateway?.enabled),
)
const depositRequired = computed(() => manualEnabled.value || gatewayEnabled.value)
const bothMethods = computed(() => manualEnabled.value && gatewayEnabled.value)

const depositAmount = computed(() => {
  const p = paymentPolicy.value
  const price = Number(selectedService.value?.price || 0)
  if (!p || !depositRequired.value || !price) return 0
  const val = Number(p.deposit_value || 0)
  if (p.deposit_type === 'percent') return Math.round(price * val) / 100
  if (p.deposit_type === 'fixed') return Math.min(val, price)
  return 0
})

// The chosen way to pay the deposit. Fixed automatically when only one method
// is offered; the customer picks when both are.
const depositMethod = ref('')
watchEffect(() => {
  if (!depositRequired.value) {
    depositMethod.value = ''
  } else if (manualEnabled.value && !gatewayEnabled.value) {
    depositMethod.value = 'manual'
  } else if (gatewayEnabled.value && !manualEnabled.value) {
    depositMethod.value = 'gateway'
  }
})

const paymentReference = ref('')

/* ------------------------------ Step 4: Details + booking ------------------------------ */
const customer = reactive({ name: '', phone: '', email: '' })
const booking = ref(false)
const bookingMessage = ref('')
const bookingErrors = ref({})
const confirmation = ref(null)

// Submit button copy shifts for the online path (a redirect, not a save).
const submitLabel = computed(() => {
  if (booking.value) return depositMethod.value === 'gateway' ? 'Redirecting…' : 'Booking…'
  return depositMethod.value === 'gateway' ? 'Pay deposit online' : 'Confirm booking'
})

async function submitBooking() {
  // Deposit guards before we bother the server: a method must be chosen, and a
  // manual transfer needs its transaction reference.
  if (depositRequired.value && !depositMethod.value) {
    bookingMessage.value = 'Choose how you would like to pay your deposit.'
    return
  }
  if (depositMethod.value === 'manual' && !paymentReference.value.trim()) {
    bookingMessage.value = 'Enter the transaction reference for your deposit to confirm the booking.'
    return
  }

  booking.value = true
  bookingMessage.value = ''
  bookingErrors.value = {}

  const payload = {
    service_id: selectedService.value.id,
    staff_id: selectedStaff.value.id,
    date: selectedDate.value,
    start_time: selectedSlot.value,
    customer: {
      name: customer.name.trim(),
      phone: customer.phone.trim(),
      email: customer.email.trim() || undefined,
    },
  }
  if (depositRequired.value) {
    payload.payment_method = depositMethod.value
    if (depositMethod.value === 'manual') {
      payload.payment_reference = paymentReference.value.trim()
    }
  }
  // Only send branch_id when it is unambiguous (a single branch).
  if (salon.value?.branches?.length === 1) {
    payload.branch_id = salon.value.branches[0].id
  }

  try {
    const { data } = await api.post(`${apiBase}/book`, payload)
    // Online deposit: hand off to the gateway's hosted checkout. It returns the
    // customer to the manage page (with a ?payment= outcome) when done.
    if (data.data?.gateway_url) {
      window.location.href = data.data.gateway_url
      return
    }
    confirmation.value = data.data
    step.value = 5
  } catch (err) {
    const parsed = parseApiError(err)
    bookingErrors.value = parsed.errors
    bookingMessage.value = parsed.message || 'We could not confirm your booking. Please try again.'
    // If the slot was taken meanwhile, bounce back to pick another time.
    const noFieldErrors = !parsed.errors || Object.keys(parsed.errors).length === 0
    if (noFieldErrors && looksLikeSlotConflict(bookingMessage.value)) {
      step.value = 3
      await loadSlots()
    }
  } finally {
    booking.value = false
  }
}

/* ------------------------------ Navigation ------------------------------ */
function goStep(n) {
  // Only allow jumping to a step the user has already satisfied.
  if (n === 1) step.value = 1
  else if (n === 2 && selectedService.value) step.value = 2
  else if (n === 3 && selectedStaff.value) {
    step.value = 3
    if (!slotsLoaded.value) loadSlots()
  } else if (n === 4 && selectedSlot.value) step.value = 4
}

function goBack() {
  if (step.value > 1) step.value -= 1
}

function resetWizard() {
  step.value = 1
  selectedService.value = null
  selectedStaff.value = null
  staff.value = []
  selectedSlot.value = ''
  slots.value = []
  slotsLoaded.value = false
  selectedDate.value = todayStr()
  customer.name = ''
  customer.phone = ''
  customer.email = ''
  paymentReference.value = ''
  confirmation.value = null
  bookingMessage.value = ''
  bookingErrors.value = {}
}

onMounted(async () => {
  await loadSalon()
  if (salon.value) loadServices()
})
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <!-- Loading the salon -->
    <div v-if="loadingSalon" class="flex min-h-screen items-center justify-center px-4">
      <div class="text-center">
        <svg class="mx-auto h-7 w-7 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <p class="mt-3 text-sm text-slate-500">Loading…</p>
      </div>
    </div>

    <!-- Salon not found -->
    <div v-else-if="notFound" class="flex min-h-screen items-center justify-center px-4">
      <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100">
          <svg class="h-7 w-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h1 class="mt-4 text-lg font-semibold text-slate-900">Salon not found</h1>
        <p class="mt-1 text-sm text-slate-500">
          We couldn't find a salon at this link. Please double-check the address and try again.
        </p>
      </div>
    </div>

    <!-- Hard load error -->
    <div v-else-if="salonError" class="flex min-h-screen items-center justify-center px-4">
      <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-semibold text-slate-900">Something went wrong</h1>
        <p class="mt-1 text-sm text-slate-500">{{ salonError }}</p>
        <button
          type="button"
          class="mt-5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
          @click="loadSalon"
        >
          Try again
        </button>
      </div>
    </div>

    <!-- Booking wizard -->
    <div v-else>
      <!-- Hero -->
      <header class="bg-gradient-to-b from-indigo-600 to-indigo-700 px-4 py-10 text-center text-white sm:py-14">
        <p class="text-xs font-medium uppercase tracking-widest text-indigo-200">Book an appointment</p>
        <h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ salon.name }}</h1>
        <p v-if="primaryBranch" class="mt-2 text-sm text-indigo-100">
          <span class="font-medium">{{ primaryBranch.name }}</span>
          <template v-if="primaryBranch.city"> · {{ primaryBranch.city }}</template>
          <template v-if="primaryBranch.address"> · {{ primaryBranch.address }}</template>
        </p>
      </header>

      <main class="mx-auto -mt-6 w-full max-w-2xl px-4 pb-16">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-7">
          <!-- Stepper -->
          <ol v-if="step <= 4" class="mb-6 flex items-center gap-2">
            <li v-for="(label, i) in STEPS" :key="label" class="flex flex-1 items-center gap-2">
              <button
                type="button"
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition"
                :class="[
                  step > i + 1
                    ? 'bg-indigo-600 text-white'
                    : step === i + 1
                      ? 'bg-indigo-600 text-white ring-4 ring-indigo-100'
                      : 'bg-slate-100 text-slate-400',
                ]"
                @click="goStep(i + 1)"
              >
                <svg v-if="step > i + 1" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                <span v-else>{{ i + 1 }}</span>
              </button>
              <span
                class="hidden text-xs font-medium sm:block"
                :class="step === i + 1 ? 'text-slate-900' : 'text-slate-400'"
              >
                {{ label }}
              </span>
              <span
                v-if="i < STEPS.length - 1"
                class="h-px flex-1 rounded"
                :class="step > i + 1 ? 'bg-indigo-600' : 'bg-slate-200'"
              ></span>
            </li>
          </ol>

          <!-- ============ STEP 1: Service ============ -->
          <section v-if="step === 1">
            <h2 class="text-lg font-semibold text-slate-900">Choose a service</h2>
            <p class="mt-1 text-sm text-slate-500">Pick the treatment you'd like to book.</p>

            <div v-if="servicesLoading" class="py-12 text-center">
              <svg class="mx-auto h-6 w-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <p class="mt-3 text-sm text-slate-500">Loading services…</p>
            </div>

            <div v-else-if="servicesError" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
              {{ servicesError }}
              <button type="button" class="ml-2 font-medium underline" @click="loadServices">Retry</button>
            </div>

            <p v-else-if="services.length === 0" class="mt-6 rounded-lg bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
              This salon has no bookable services right now.
            </p>

            <div v-else class="mt-5 space-y-3">
              <button
                v-for="svc in services"
                :key="svc.id"
                type="button"
                class="flex w-full items-center justify-between gap-4 rounded-xl border p-4 text-left transition"
                :class="selectedService?.id === svc.id
                  ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                  : 'border-slate-200 bg-white hover:border-indigo-300 hover:bg-slate-50'"
                @click="selectService(svc)"
              >
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-slate-900">{{ svc.name }}</span>
                    <span v-if="svc.category" class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                      {{ svc.category.name }}
                    </span>
                  </div>
                  <p v-if="svc.description" class="mt-1 line-clamp-2 text-sm text-slate-500">{{ svc.description }}</p>
                  <p class="mt-1 text-xs text-slate-400">{{ svc.duration }} min</p>
                </div>
                <div class="shrink-0 text-right">
                  <span v-if="formatPrice(svc.price)" class="text-base font-semibold text-slate-900">{{ formatPrice(svc.price) }}</span>
                  <svg class="ml-auto mt-1 h-5 w-5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                  </svg>
                </div>
              </button>
            </div>
          </section>

          <!-- ============ STEP 2: Staff ============ -->
          <section v-else-if="step === 2">
            <h2 class="text-lg font-semibold text-slate-900">Choose a professional</h2>
            <p class="mt-1 text-sm text-slate-500">Who would you like for your {{ selectedService?.name }}?</p>

            <div v-if="staffLoading" class="py-12 text-center">
              <svg class="mx-auto h-6 w-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <p class="mt-3 text-sm text-slate-500">Loading team…</p>
            </div>

            <div v-else-if="staffError" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
              {{ staffError }}
              <button type="button" class="ml-2 font-medium underline" @click="loadStaff">Retry</button>
            </div>

            <p v-else-if="staff.length === 0" class="mt-6 rounded-lg bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
              No one is available for this service right now. Try another service.
            </p>

            <div v-else class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <button
                v-for="member in staff"
                :key="member.id"
                type="button"
                class="flex items-center gap-3 rounded-xl border p-4 text-left transition"
                :class="selectedStaff?.id === member.id
                  ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                  : 'border-slate-200 bg-white hover:border-indigo-300 hover:bg-slate-50'"
                @click="selectStaff(member)"
              >
                <img
                  v-if="member.profile_image"
                  :src="member.profile_image"
                  :alt="member.name"
                  class="h-11 w-11 shrink-0 rounded-full object-cover"
                />
                <div
                  v-else
                  class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700"
                >
                  {{ initials(member.name) }}
                </div>
                <div class="min-w-0">
                  <p class="truncate font-semibold text-slate-900">{{ member.name }}</p>
                  <p v-if="member.designation" class="truncate text-xs text-slate-500">{{ member.designation }}</p>
                </div>
              </button>
            </div>

            <div class="mt-6">
              <button type="button" class="text-sm font-medium text-slate-500 transition hover:text-slate-700" @click="goBack">
                ← Back
              </button>
            </div>
          </section>

          <!-- ============ STEP 3: Date & time ============ -->
          <section v-else-if="step === 3">
            <h2 class="text-lg font-semibold text-slate-900">Pick a date &amp; time</h2>
            <p class="mt-1 text-sm text-slate-500">Available times for {{ selectedStaff?.name }}.</p>

            <div class="mt-5">
              <label class="mb-1 block text-sm font-medium text-slate-700">Date</label>
              <input
                v-model="selectedDate"
                type="date"
                :min="todayStr()"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 sm:w-auto"
              />
            </div>

            <div v-if="slotsLoading" class="py-10 text-center">
              <svg class="mx-auto h-6 w-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <p class="mt-3 text-sm text-slate-500">Finding open times…</p>
            </div>

            <div v-else-if="slotsError" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
              {{ slotsError }}
              <button type="button" class="ml-2 font-medium underline" @click="loadSlots">Retry</button>
            </div>

            <p v-else-if="slots.length === 0" class="mt-5 rounded-lg bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
              No open times on this day, try another date.
            </p>

            <div v-else class="mt-5 grid grid-cols-3 gap-2 sm:grid-cols-4">
              <button
                v-for="slot in slots"
                :key="slot"
                type="button"
                class="rounded-lg border px-2 py-2.5 text-sm font-medium transition"
                :class="selectedSlot === slot
                  ? 'border-indigo-500 bg-indigo-600 text-white'
                  : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300 hover:bg-indigo-50'"
                @click="selectSlot(slot)"
              >
                {{ formatTime(slot) }}
              </button>
            </div>

            <div class="mt-6">
              <button type="button" class="text-sm font-medium text-slate-500 transition hover:text-slate-700" @click="goBack">
                ← Back
              </button>
            </div>
          </section>

          <!-- ============ STEP 4: Details ============ -->
          <section v-else-if="step === 4">
            <h2 class="text-lg font-semibold text-slate-900">Your details</h2>
            <p class="mt-1 text-sm text-slate-500">Almost done — tell us who's coming in.</p>

            <!-- Summary -->
            <dl class="mt-5 space-y-2 rounded-xl bg-slate-50 p-4 text-sm">
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Service</dt>
                <dd class="text-right font-medium text-slate-900">{{ selectedService?.name }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Professional</dt>
                <dd class="text-right font-medium text-slate-900">{{ selectedStaff?.name }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">When</dt>
                <dd class="text-right font-medium text-slate-900">
                  {{ formatDate(selectedDate) }} · {{ formatTime(selectedSlot) }}
                </dd>
              </div>
              <div v-if="depositRequired && depositAmount" class="flex justify-between gap-4 border-t border-slate-200 pt-2">
                <dt class="text-slate-500">Deposit to confirm</dt>
                <dd class="text-right font-semibold text-indigo-700">{{ formatPrice(depositAmount) }}</dd>
              </div>
            </dl>

            <!-- Deposit payment -->
            <div
              v-if="depositRequired"
              class="mt-5 rounded-xl border border-indigo-200 bg-indigo-50/60 p-4"
            >
              <h3 class="text-sm font-semibold text-indigo-900">Pay your deposit</h3>
              <p class="mt-1 text-sm text-indigo-800">
                A <span class="font-semibold">{{ formatPrice(depositAmount) }}</span> deposit secures this booking.
              </p>

              <!-- Method chooser: only when the salon offers both. -->
              <div v-if="bothMethods" class="mt-3 grid gap-2 sm:grid-cols-2">
                <label
                  :class="[
                    'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition',
                    depositMethod === 'gateway'
                      ? 'border-indigo-500 bg-white text-indigo-900 shadow-sm'
                      : 'border-indigo-200 bg-indigo-50 text-indigo-700 hover:border-indigo-300',
                  ]"
                >
                  <input v-model="depositMethod" type="radio" value="gateway" class="text-indigo-600" />
                  Pay online
                </label>
                <label
                  :class="[
                    'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition',
                    depositMethod === 'manual'
                      ? 'border-indigo-500 bg-white text-indigo-900 shadow-sm'
                      : 'border-indigo-200 bg-indigo-50 text-indigo-700 hover:border-indigo-300',
                  ]"
                >
                  <input v-model="depositMethod" type="radio" value="manual" class="text-indigo-600" />
                  Bank / wallet transfer
                </label>
              </div>

              <!-- Online gateway -->
              <div v-if="depositMethod === 'gateway'" class="mt-3 rounded-lg bg-white/70 px-3 py-3 text-sm text-indigo-800">
                You'll be sent to a secure page to pay
                <span class="font-semibold">{{ formatPrice(depositAmount) }}</span>
                by card or mobile banking. Your booking is confirmed as soon as the payment succeeds.
              </div>

              <!-- Manual transfer -->
              <div v-if="depositMethod === 'manual'" class="mt-3">
                <p class="text-sm text-indigo-800">
                  Send <span class="font-semibold">{{ formatPrice(depositAmount) }}</span>, then enter the
                  transaction reference below.
                </p>

                <dl class="mt-3 space-y-1 text-sm">
                  <div v-if="paymentPolicy?.manual?.account_number" class="flex justify-between gap-4">
                    <dt class="text-indigo-700">Send to</dt>
                    <dd class="text-right font-medium text-indigo-900">{{ paymentPolicy.manual.account_number }}</dd>
                  </div>
                </dl>
                <p v-if="paymentPolicy?.manual?.instructions" class="mt-2 whitespace-pre-line text-xs text-indigo-700">
                  {{ paymentPolicy.manual.instructions }}
                </p>

                <div class="mt-3">
                  <label class="mb-1 block text-sm font-medium text-indigo-900">
                    Transaction reference <span class="text-rose-500">*</span>
                  </label>
                  <input
                    v-model="paymentReference"
                    type="text"
                    placeholder="e.g. TXN123456"
                    class="w-full rounded-lg border border-indigo-300 bg-white px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                  />
                  <p v-if="bookingErrors['payment_reference']" class="mt-1 text-sm text-rose-600">
                    {{ bookingErrors['payment_reference'][0] }}
                  </p>
                  <p class="mt-1 text-xs text-indigo-600">
                    Your deposit is held as pending until the salon confirms it arrived.
                  </p>
                </div>
              </div>
            </div>

            <div
              v-if="bookingMessage"
              class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
            >
              {{ bookingMessage }}
            </div>

            <form class="mt-5 space-y-4" @submit.prevent="submitBooking">
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Full name <span class="text-rose-500">*</span></label>
                <input
                  v-model="customer.name"
                  type="text"
                  required
                  placeholder="Jane Doe"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                />
                <p v-if="bookingErrors['customer.name']" class="mt-1 text-sm text-rose-600">{{ bookingErrors['customer.name'][0] }}</p>
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Phone <span class="text-rose-500">*</span></label>
                <input
                  v-model="customer.phone"
                  type="tel"
                  required
                  placeholder="+1 555 000 1234"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                />
                <p v-if="bookingErrors['customer.phone']" class="mt-1 text-sm text-rose-600">{{ bookingErrors['customer.phone'][0] }}</p>
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Email <span class="text-slate-400">(optional)</span></label>
                <input
                  v-model="customer.email"
                  type="email"
                  placeholder="jane@example.com"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                />
                <p v-if="bookingErrors['customer.email']" class="mt-1 text-sm text-rose-600">{{ bookingErrors['customer.email'][0] }}</p>
              </div>

              <div class="flex items-center justify-between gap-3 pt-2">
                <button type="button" class="text-sm font-medium text-slate-500 transition hover:text-slate-700" @click="goBack">
                  ← Back
                </button>
                <button
                  type="submit"
                  :disabled="booking"
                  class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <svg v-if="booking" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  {{ submitLabel }}
                </button>
              </div>
            </form>
          </section>

          <!-- ============ STEP 5: Success ============ -->
          <section v-else-if="step === 5 && confirmation" class="py-4 text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100">
              <svg class="h-9 w-9 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
              </svg>
            </div>
            <h2 class="mt-4 text-xl font-bold text-slate-900">Booking confirmed</h2>
            <p class="mt-1 text-sm text-slate-500">We've saved your appointment. See you soon, {{ confirmation.customer?.name }}!</p>

            <dl class="mx-auto mt-6 max-w-sm space-y-2 rounded-xl bg-slate-50 p-5 text-left text-sm">
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Date</dt>
                <dd class="text-right font-medium text-slate-900">{{ formatDate(confirmation.date) }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Time</dt>
                <dd class="text-right font-medium text-slate-900">
                  {{ formatTime(confirmation.start_time) }}<template v-if="confirmation.end_time"> – {{ formatTime(confirmation.end_time) }}</template>
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Service</dt>
                <dd class="text-right font-medium text-slate-900">{{ confirmation.service?.name }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Professional</dt>
                <dd class="text-right font-medium text-slate-900">{{ confirmation.staff?.name }}</dd>
              </div>
              <div v-if="confirmation.branch?.name" class="flex justify-between gap-4">
                <dt class="text-slate-500">Location</dt>
                <dd class="text-right font-medium text-slate-900">{{ confirmation.branch.name }}</dd>
              </div>
              <div v-if="confirmation.status" class="flex justify-between gap-4">
                <dt class="text-slate-500">Status</dt>
                <dd class="text-right font-medium capitalize text-slate-900">{{ confirmation.status }}</dd>
              </div>
              <div
                v-if="Number(confirmation.payment?.amount_pending) > 0"
                class="flex justify-between gap-4 border-t border-slate-200 pt-2"
              >
                <dt class="text-slate-500">Deposit</dt>
                <dd class="text-right font-medium text-amber-600">
                  {{ formatPrice(confirmation.payment.amount_pending) }} · pending
                </dd>
              </div>
            </dl>

            <p
              v-if="Number(confirmation.payment?.amount_pending) > 0"
              class="mx-auto mt-3 max-w-sm text-xs text-slate-500"
            >
              We've recorded your deposit reference. The salon will confirm it shortly.
            </p>

            <p v-if="confirmation.public_token" class="mt-6 text-sm text-slate-500">
              Need to make a change?
              <a
                :href="`/book/${slug}/manage/${confirmation.public_token}`"
                class="font-medium text-indigo-600 hover:text-indigo-700"
              >
                Manage this booking
              </a>
            </p>

            <button
              type="button"
              class="mt-4 rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
              @click="resetWizard"
            >
              Book another
            </button>
          </section>
        </div>

        <p class="mt-6 text-center text-xs text-slate-400">Powered by SalonHub</p>
      </main>
    </div>
  </div>
</template>
