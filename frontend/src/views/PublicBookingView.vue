<script setup>
import { computed, onMounted, reactive, ref, watch, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/lib/api'
import { customerToken, fetchCustomerIdentity } from '@/lib/customerApi'
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

// Short form for the "Available — Fri, Aug 14" heading over the time chips.
function formatDateShort(dateStr) {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-').map(Number)
  if (!y || !m || !d) return dateStr
  return new Date(y, m - 1, d).toLocaleDateString(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
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
  if (Number.isNaN(num)) return value
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: salon.value?.currency || 'USD',
      maximumFractionDigits: 2,
    }).format(num)
  } catch {
    return num.toFixed(2)
  }
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

// The wizard is the salon site's dark room by another door: an untouched
// salon keeps the brass rather than the API's indigo placeholder.
const accent = computed(() => {
  const chosen = salon.value?.theme_color
  return !chosen || chosen.toLowerCase() === '#6366f1' ? '#c8a45d' : chosen
})

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
    slotsLoaded.value = false
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

// Choosing is not committing: the customer picks a face, then presses
// Continue. Times are fetched on the choice so step 3 opens already filled.
function selectStaff(member) {
  const changed = selectedStaff.value?.id !== member.id
  selectedStaff.value = member
  if (changed) {
    selectedSlot.value = ''
    slots.value = []
    slotsLoaded.value = false
    loadSlots()
  }
}

/* ------------------------------ Step 3: Date & time ------------------------------ */
const selectedDate = ref(todayStr())
const slots = ref([])
const slotsLoading = ref(false)
const slotsError = ref('')
const slotsLoaded = ref(false)
const selectedSlot = ref('')

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']

// First of the month currently drawn in the calendar, as [year, monthIndex].
const calendar = reactive((() => {
  const now = new Date()
  return { year: now.getFullYear(), month: now.getMonth() }
})())

const calendarLabel = computed(() =>
  new Date(calendar.year, calendar.month, 1).toLocaleDateString(undefined, {
    month: 'long',
    year: 'numeric',
  }),
)

function dateStr(year, month, day) {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

// Leading blanks so the first day lands under its weekday, then the days
// themselves. A day before today is shown but cannot be picked.
const calendarDays = computed(() => {
  const first = new Date(calendar.year, calendar.month, 1)
  const total = new Date(calendar.year, calendar.month + 1, 0).getDate()
  const cells = Array.from({ length: first.getDay() }, () => null)
  const today = todayStr()

  for (let day = 1; day <= total; day += 1) {
    const value = dateStr(calendar.year, calendar.month, day)
    cells.push({ day, value, past: value < today })
  }

  return cells
})

// Nothing to go back to once the calendar reaches the current month.
const canGoBackAMonth = computed(() => {
  const now = new Date()
  return calendar.year > now.getFullYear() || (calendar.year === now.getFullYear() && calendar.month > now.getMonth())
})

function shiftMonth(delta) {
  if (delta < 0 && !canGoBackAMonth.value) return
  const next = new Date(calendar.year, calendar.month + delta, 1)
  calendar.year = next.getFullYear()
  calendar.month = next.getMonth()
}

function pickDate(cell) {
  if (!cell || cell.past) return
  selectedDate.value = cell.value
}

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

// Reload slots whenever the date changes while on (or past) the time step.
watch(selectedDate, () => {
  if (step.value >= 3) loadSlots()
})

function selectSlot(slot) {
  selectedSlot.value = slot
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
// The signed-in customer, when one is. Their account supplies the identity, so
// the form only asks for what it does not already know.
const account = ref(null)
const needsName = computed(() => !account.value?.name)
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

  // Signed in: the server takes the name and email off the account, so only
  // send what the form still asked for.
  const who = { phone: customer.phone.trim() }
  if (needsName.value) who.name = customer.name.trim()
  if (!account.value) who.email = customer.email.trim() || undefined

  const payload = {
    service_id: selectedService.value.id,
    staff_id: selectedStaff.value.id,
    date: selectedDate.value,
    start_time: selectedSlot.value,
    customer: who,
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
    // The booking endpoint is anonymous, but a customer token on it makes the
    // booking the account's — which is what lands it on their dashboard.
    const headers = account.value ? { Authorization: `Bearer ${customerToken()}` } : {}
    const { data } = await api.post(`${apiBase}/book`, payload, { headers })
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
  customer.phone = account.value?.phone || ''
  customer.email = ''
  paymentReference.value = ''
  confirmation.value = null
  bookingMessage.value = ''
  bookingErrors.value = {}
}

onMounted(async () => {
  // Resolved alongside the salon, not gated behind it: a stale token simply
  // resolves to null and the wizard books as a guest.
  fetchCustomerIdentity().then((found) => {
    account.value = found
    if (found?.phone && !customer.phone) customer.phone = found.phone
  })

  await loadSalon()
  if (salon.value) loadServices()
})
</script>

<template>
  <div class="booking" :style="{ '--accent': accent }">
    <!-- Loading the salon -->
    <div v-if="loadingSalon" class="flex min-h-screen items-center justify-center px-6">
      <div class="text-center">
        <span class="spinner" />
        <p class="label mt-4 text-white/40">Loading</p>
      </div>
    </div>

    <!-- Salon not found -->
    <div v-else-if="notFound" class="flex min-h-screen items-center justify-center px-6">
      <div class="panel w-full max-w-md p-10 text-center">
        <h1 class="font-display text-3xl text-white">Salon not found</h1>
        <p class="mt-3 text-sm leading-relaxed text-white/45">
          We couldn't find a salon at this link. Please double-check the address and try again.
        </p>
      </div>
    </div>

    <!-- Hard load error -->
    <div v-else-if="salonError" class="flex min-h-screen items-center justify-center px-6">
      <div class="panel w-full max-w-md p-10 text-center">
        <h1 class="font-display text-3xl text-white">Something went wrong</h1>
        <p class="mt-3 text-sm text-white/45">{{ salonError }}</p>
        <button type="button" class="btn-gold mt-7" @click="loadSalon">Try again</button>
      </div>
    </div>

    <!-- Booking wizard -->
    <div v-else class="relative min-h-screen">
      <!-- Hero: the salon's own cover, dimmed to a backdrop -->
      <div class="absolute inset-x-0 top-0 -z-10 h-[19rem] overflow-hidden">
        <img
          v-if="salon.cover_image_url"
          :src="salon.cover_image_url"
          alt=""
          class="h-full w-full object-cover opacity-45"
        />
        <div v-else class="h-full w-full bg-[radial-gradient(110%_100%_at_50%_0%,#2a241d_0%,#080706_70%)]" />
        <div class="absolute inset-0 bg-[linear-gradient(to_bottom,rgba(8,7,6,0.55)_0%,rgba(8,7,6,0.86)_60%,#080706_100%)]" />
      </div>

      <RouterLink :to="`/salon/${slug}`" class="label absolute top-7 left-6 z-10 text-white/55 transition hover:text-white lg:left-10">
        ← Back to salon
      </RouterLink>

      <header class="px-6 pt-24 pb-14 text-center">
        <p class="rule-label justify-center text-[var(--accent)]">Book an appointment</p>
        <h1 class="mt-5 font-display text-[clamp(2.2rem,6vw,3.4rem)] leading-tight text-white">{{ salon.name }}</h1>
        <p v-if="primaryBranch" class="mt-3 text-sm text-white/45">
          {{ primaryBranch.name }}
          <template v-if="primaryBranch.city"> · {{ primaryBranch.city }}</template>
          <template v-if="primaryBranch.address"> · {{ primaryBranch.address }}</template>
        </p>
      </header>

      <main class="mx-auto w-full max-w-3xl px-6 pb-20">
        <div class="panel">
          <!-- Stepper -->
          <ol v-if="step <= 4" class="flex items-center gap-3 border-b border-white/8 px-6 py-5 sm:px-9">
            <li v-for="(label, i) in STEPS" :key="label" class="flex flex-1 items-center gap-3">
              <button
                type="button"
                class="flex h-8 w-8 shrink-0 items-center justify-center border text-xs font-semibold transition"
                :class="step > i + 1
                  ? 'border-[var(--accent)] bg-[var(--accent)] text-[#0a0908]'
                  : step === i + 1
                    ? 'border-[var(--accent)] text-[var(--accent)]'
                    : 'border-white/15 text-white/30'"
                @click="goStep(i + 1)"
              >
                <svg v-if="step > i + 1" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                <span v-else>{{ i + 1 }}</span>
              </button>
              <span
                class="hidden whitespace-nowrap text-sm sm:block"
                :class="step === i + 1 ? 'text-white' : step > i + 1 ? 'text-[var(--accent)]' : 'text-white/35'"
              >
                {{ label }}
              </span>
              <span
                v-if="i < STEPS.length - 1"
                class="h-px flex-1"
                :class="step > i + 1 ? 'bg-[var(--accent)]/50' : 'bg-white/10'"
              />
            </li>
          </ol>

          <div class="px-6 py-9 sm:px-9 sm:py-11">
            <!-- ============ STEP 1: Service ============ -->
            <section v-if="step === 1">
              <h2 class="font-display text-3xl text-white">Choose a service</h2>
              <p class="mt-2 text-sm text-white/45">Pick the treatment you'd like to book.</p>

              <div v-if="servicesLoading" class="py-16 text-center">
                <span class="spinner" />
                <p class="label mt-4 text-white/40">Loading services</p>
              </div>

              <div v-else-if="servicesError" class="alert-error mt-7">
                {{ servicesError }}
                <button type="button" class="ml-2 underline" @click="loadServices">Retry</button>
              </div>

              <p v-else-if="services.length === 0" class="empty mt-7">
                This salon has no bookable services right now.
              </p>

              <div v-else class="mt-8 space-y-3">
                <button
                  v-for="svc in services"
                  :key="svc.id"
                  type="button"
                  class="option flex w-full items-center justify-between gap-5 p-5 text-left"
                  :class="selectedService?.id === svc.id ? 'option-on' : ''"
                  @click="selectService(svc)"
                >
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                      <span class="font-display text-lg text-white">{{ svc.name }}</span>
                      <span v-if="svc.category" class="chip">{{ svc.category.name }}</span>
                    </div>
                    <p v-if="svc.description" class="mt-1.5 line-clamp-2 text-sm text-white/40">{{ svc.description }}</p>
                    <p class="mt-1.5 text-sm text-white/35">{{ svc.duration }} min</p>
                  </div>
                  <div class="flex shrink-0 items-center gap-3">
                    <span v-if="formatPrice(svc.price)" class="font-display text-xl text-white">{{ formatPrice(svc.price) }}</span>
                    <svg class="h-4 w-4 text-white/25" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                  </div>
                </button>
              </div>
            </section>

            <!-- ============ STEP 2: Staff ============ -->
            <section v-else-if="step === 2">
              <h2 class="font-display text-3xl text-white">Choose a professional</h2>
              <p class="mt-2 text-sm text-white/45">Who would you like for your {{ selectedService?.name }}?</p>

              <div v-if="staffLoading" class="py-16 text-center">
                <span class="spinner" />
                <p class="label mt-4 text-white/40">Loading team</p>
              </div>

              <div v-else-if="staffError" class="alert-error mt-7">
                {{ staffError }}
                <button type="button" class="ml-2 underline" @click="loadStaff">Retry</button>
              </div>

              <p v-else-if="staff.length === 0" class="empty mt-7">
                No one is available for this service right now. Try another service.
              </p>

              <div v-else class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <button
                  v-for="member in staff"
                  :key="member.id"
                  type="button"
                  class="option group overflow-hidden text-left"
                  :class="selectedStaff?.id === member.id ? 'option-on' : ''"
                  @click="selectStaff(member)"
                >
                  <div class="relative aspect-4/3 overflow-hidden bg-[#0a0908]">
                    <img
                      v-if="member.profile_image"
                      :src="member.profile_image"
                      :alt="member.name"
                      class="h-full w-full object-cover grayscale transition duration-700 group-hover:grayscale-0"
                    />
                    <div v-else class="flex h-full w-full items-center justify-center font-display text-5xl text-white/15">
                      {{ initials(member.name) }}
                    </div>
                  </div>
                  <div class="p-5">
                    <p class="truncate font-display text-lg text-white">{{ member.name }}</p>
                    <p v-if="member.designation" class="label mt-1 truncate text-white/40">{{ member.designation }}</p>
                  </div>
                </button>
              </div>

              <div class="mt-10 flex items-center justify-between gap-4">
                <button type="button" class="btn-text" @click="goBack">← Back</button>
                <button type="button" class="btn-gold" :disabled="!selectedStaff" @click="goStep(3)">
                  Continue →
                </button>
              </div>
            </section>

            <!-- ============ STEP 3: Date & time ============ -->
            <section v-else-if="step === 3">
              <h2 class="font-display text-3xl text-white">Date &amp; time</h2>
              <p class="mt-2 text-sm text-white/45">Select when you'd like your {{ selectedService?.name }}.</p>

              <!-- Calendar -->
              <div class="mt-9">
                <div class="flex items-center justify-between gap-4">
                  <button
                    type="button"
                    class="cal-nav"
                    :disabled="!canGoBackAMonth"
                    aria-label="Previous month"
                    @click="shiftMonth(-1)"
                  >
                    ‹
                  </button>
                  <p class="text-base text-white">{{ calendarLabel }}</p>
                  <button type="button" class="cal-nav" aria-label="Next month" @click="shiftMonth(1)">›</button>
                </div>

                <div class="mt-7 grid grid-cols-7 gap-y-2 text-center">
                  <span v-for="day in WEEKDAYS" :key="day" class="label pb-3 text-white/30">{{ day }}</span>
                  <template v-for="(cell, i) in calendarDays" :key="i">
                    <span v-if="!cell" />
                    <button
                      v-else
                      type="button"
                      class="cal-day"
                      :class="[
                        cell.value === selectedDate ? 'cal-day-on' : '',
                        cell.past ? 'cal-day-off' : '',
                      ]"
                      :disabled="cell.past"
                      @click="pickDate(cell)"
                    >
                      {{ cell.day }}
                    </button>
                  </template>
                </div>
              </div>

              <!-- Times -->
              <div v-if="slotsLoading" class="py-14 text-center">
                <span class="spinner" />
                <p class="label mt-4 text-white/40">Finding open times</p>
              </div>

              <div v-else-if="slotsError" class="alert-error mt-9">
                {{ slotsError }}
                <button type="button" class="ml-2 underline" @click="loadSlots">Retry</button>
              </div>

              <p v-else-if="slots.length === 0" class="empty mt-9">
                No open times on this day, try another date.
              </p>

              <div v-else class="mt-11">
                <p class="rule-label text-white/35">Available — {{ formatDateShort(selectedDate) }}</p>
                <div class="mt-5 grid grid-cols-3 gap-2.5 sm:grid-cols-6">
                  <button
                    v-for="slot in slots"
                    :key="slot"
                    type="button"
                    class="slot"
                    :class="selectedSlot === slot ? 'slot-on' : ''"
                    @click="selectSlot(slot)"
                  >
                    {{ formatTime(slot) }}
                  </button>
                </div>
              </div>

              <div class="mt-10 flex items-center justify-between gap-4">
                <button type="button" class="btn-text" @click="goBack">← Back</button>
                <button type="button" class="btn-gold" :disabled="!selectedSlot" @click="goStep(4)">
                  Continue →
                </button>
              </div>
            </section>

            <!-- ============ STEP 4: Details ============ -->
            <section v-else-if="step === 4">
              <h2 class="font-display text-3xl text-white">Your details</h2>
              <p class="mt-2 text-sm text-white/45">Almost done — tell us who's coming in.</p>

              <!-- Summary -->
              <dl class="summary mt-8">
                <div>
                  <dt>Service</dt>
                  <dd>{{ selectedService?.name }}</dd>
                </div>
                <div>
                  <dt>Professional</dt>
                  <dd>{{ selectedStaff?.name }}</dd>
                </div>
                <div>
                  <dt>When</dt>
                  <dd>{{ formatDate(selectedDate) }} · {{ formatTime(selectedSlot) }}</dd>
                </div>
                <div v-if="depositRequired && depositAmount" class="summary-total">
                  <dt>Deposit to confirm</dt>
                  <dd class="dd-accent">{{ formatPrice(depositAmount) }}</dd>
                </div>
              </dl>

              <!-- Deposit payment -->
              <div v-if="depositRequired" class="mt-7 border border-[var(--accent)]/30 bg-[var(--accent)]/5 p-6">
                <h3 class="rule-label text-[var(--accent)]">Pay your deposit</h3>
                <p class="mt-4 text-sm text-white/65">
                  A <span class="text-white">{{ formatPrice(depositAmount) }}</span> deposit secures this booking.
                </p>

                <!-- Method chooser: only when the salon offers both. -->
                <div v-if="bothMethods" class="mt-5 grid gap-3 sm:grid-cols-2">
                  <label class="method" :class="depositMethod === 'gateway' ? 'method-on' : ''">
                    <input v-model="depositMethod" type="radio" value="gateway" class="accent-[var(--accent)]" />
                    Pay online
                  </label>
                  <label class="method" :class="depositMethod === 'manual' ? 'method-on' : ''">
                    <input v-model="depositMethod" type="radio" value="manual" class="accent-[var(--accent)]" />
                    Bank / wallet transfer
                  </label>
                </div>

                <!-- Online gateway -->
                <p v-if="depositMethod === 'gateway'" class="mt-5 border border-white/8 bg-[#0a0908] p-4 text-sm leading-relaxed text-white/55">
                  You'll be sent to a secure page to pay
                  <span class="text-white">{{ formatPrice(depositAmount) }}</span>
                  by card or mobile banking. Your booking is confirmed as soon as the payment succeeds.
                </p>

                <!-- Manual transfer -->
                <div v-if="depositMethod === 'manual'" class="mt-5">
                  <p class="text-sm text-white/55">
                    Send <span class="text-white">{{ formatPrice(depositAmount) }}</span>, then enter the
                    transaction reference below.
                  </p>

                  <dl v-if="paymentPolicy?.manual?.account_number" class="summary mt-4">
                    <div>
                      <dt>Send to</dt>
                      <dd>{{ paymentPolicy.manual.account_number }}</dd>
                    </div>
                  </dl>
                  <p v-if="paymentPolicy?.manual?.instructions" class="mt-3 text-sm leading-relaxed whitespace-pre-line text-white/40">
                    {{ paymentPolicy.manual.instructions }}
                  </p>

                  <div class="mt-5">
                    <label class="field-label">Transaction reference <span class="text-[var(--accent)]">*</span></label>
                    <input v-model="paymentReference" type="text" placeholder="e.g. TXN123456" class="field" />
                    <p v-if="bookingErrors['payment_reference']" class="field-error">
                      {{ bookingErrors['payment_reference'][0] }}
                    </p>
                    <p class="mt-2 text-sm text-white/35">
                      Your deposit is held as pending until the salon confirms it arrived.
                    </p>
                  </div>
                </div>
              </div>

              <div v-if="bookingMessage" class="alert-error mt-7">{{ bookingMessage }}</div>

              <form class="mt-8 space-y-6" @submit.prevent="submitBooking">
                <!-- Signed in: the account is the identity, so the form does
                     not ask for it again. It only says who is booking. -->
                <div v-if="account" class="identity">
                  <span class="identity-label">Booking as</span>
                  <span class="identity-name">{{ account.name || account.email }}</span>
                  <span v-if="account.name" class="identity-email">{{ account.email }}</span>
                </div>

                <div v-if="needsName">
                  <label class="field-label">Full name <span class="text-[var(--accent)]">*</span></label>
                  <input v-model="customer.name" type="text" required placeholder="Jane Doe" class="field" />
                  <p v-if="bookingErrors['customer.name']" class="field-error">{{ bookingErrors['customer.name'][0] }}</p>
                </div>
                <div>
                  <label class="field-label">Phone <span class="text-[var(--accent)]">*</span></label>
                  <input v-model="customer.phone" type="tel" required placeholder="+1 555 000 1234" class="field" />
                  <p v-if="bookingErrors['customer.phone']" class="field-error">{{ bookingErrors['customer.phone'][0] }}</p>
                </div>
                <div v-if="!account">
                  <label class="field-label">Email <span class="text-white/30">(optional)</span></label>
                  <input v-model="customer.email" type="email" placeholder="jane@example.com" class="field" />
                  <p v-if="bookingErrors['customer.email']" class="field-error">{{ bookingErrors['customer.email'][0] }}</p>
                </div>

                <div class="flex items-center justify-between gap-4 pt-2">
                  <button type="button" class="btn-text" @click="goBack">← Back</button>
                  <button type="submit" :disabled="booking" class="btn-gold">
                    <span v-if="booking" class="spinner spinner-sm" />
                    {{ submitLabel }}
                    <svg v-if="!booking" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                  </button>
                </div>
              </form>
            </section>

            <!-- ============ STEP 5: Success ============ -->
            <section v-else-if="step === 5 && confirmation" class="py-4 text-center">
              <div class="relative mx-auto h-16 w-16">
                <div class="flex h-full w-full items-center justify-center border border-[var(--accent)]">
                  <svg class="h-7 w-7 text-[var(--accent)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                  </svg>
                </div>
                <span class="absolute -top-1.5 -right-1.5 h-3 w-3 bg-[var(--accent)]" />
              </div>

              <h2 class="mt-7 font-display text-3xl text-white">Booking confirmed</h2>
              <p class="mt-2 text-sm text-white/45">
                We've saved your appointment. See you soon, <span class="text-white">{{ confirmation.customer?.name }}</span>.
              </p>

              <dl class="summary mt-9 text-left">
                <div>
                  <dt>Date</dt>
                  <dd>{{ formatDate(confirmation.date) }}</dd>
                </div>
                <div>
                  <dt>Time</dt>
                  <dd>
                    {{ formatTime(confirmation.start_time) }}<template v-if="confirmation.end_time"> – {{ formatTime(confirmation.end_time) }}</template>
                  </dd>
                </div>
                <div>
                  <dt>Service</dt>
                  <dd>{{ confirmation.service?.name }}</dd>
                </div>
                <div>
                  <dt>Professional</dt>
                  <dd>{{ confirmation.staff?.name }}</dd>
                </div>
                <div v-if="confirmation.branch?.name">
                  <dt>Location</dt>
                  <dd>{{ confirmation.branch.name }}</dd>
                </div>
                <div v-if="confirmation.status">
                  <dt>Status</dt>
                  <dd class="dd-accent capitalize">{{ confirmation.status }}</dd>
                </div>
                <div v-if="Number(confirmation.payment?.amount_pending) > 0" class="summary-total">
                  <dt>Deposit</dt>
                  <dd class="dd-accent">{{ formatPrice(confirmation.payment.amount_pending) }} · pending</dd>
                </div>
              </dl>

              <p v-if="Number(confirmation.payment?.amount_pending) > 0" class="mt-4 text-sm text-white/35">
                We've recorded your deposit reference. The salon will confirm it shortly.
              </p>

              <p v-if="confirmation.public_token" class="mt-9 text-sm text-white/45">
                Need to make a change?
                <a
                  :href="`/book/${slug}/manage/${confirmation.public_token}`"
                  class="text-[var(--accent)] underline underline-offset-4 transition hover:text-white"
                >
                  Manage this booking
                </a>
              </p>

              <div class="mt-7 flex flex-wrap justify-center gap-3">
                <button type="button" class="btn-light" @click="resetWizard">Book another</button>
                <RouterLink v-if="account" to="/account" class="btn-ghost">My bookings</RouterLink>
                <RouterLink :to="`/salon/${slug}`" class="btn-ghost">Back to salon</RouterLink>
              </div>
            </section>
          </div>
        </div>

        <p class="label mt-9 text-center text-white/25">Powered by SalonHub</p>
      </main>
    </div>
  </div>
</template>

<style scoped>
/*
 * The wizard is the salon's shopfront continued indoors: same dark room, same
 * brass, square corners. It deliberately shares no styling with the dashboard.
 */
.booking {
  background: #080706;
  color: #fff;
  font-family: var(--font-body);
  min-height: 100vh;
}

.font-display {
  font-family: var(--font-display);
  font-weight: 400;
}

.label {
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

/* Eyebrow with a brass rule on each side, centred over the salon name. */
.rule-label {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.rule-label::before {
  content: '';
  width: 1.75rem;
  height: 1px;
  background: currentColor;
  opacity: 0.7;
}

.rule-label.justify-center::after {
  content: '';
  width: 1.75rem;
  height: 1px;
  background: currentColor;
  opacity: 0.7;
}

.panel {
  background: #131110;
  border: 1px solid rgb(255 255 255 / 0.08);
}

/* Selectable card — service row, staff card, anything the customer picks. */
.option {
  border: 1px solid rgb(255 255 255 / 0.08);
  background: #0e0d0c;
  transition:
    border-color 0.3s ease,
    background-color 0.3s ease;
}

.option:hover {
  border-color: rgb(255 255 255 / 0.2);
  background: #121110;
}

.option-on {
  border-color: var(--accent);
  background: color-mix(in srgb, var(--accent) 8%, #0e0d0c);
}

.chip {
  border: 1px solid rgb(255 255 255 / 0.15);
  padding: 0.15rem 0.55rem;
  font-size: 0.7rem;
  color: rgb(255 255 255 / 0.5);
}

.btn-gold,
.btn-ghost,
.btn-light {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  padding: 0.95rem 1.9rem;
  transition:
    background-color 0.3s ease,
    color 0.3s ease,
    border-color 0.3s ease,
    opacity 0.3s ease;
  white-space: nowrap;
}

.btn-gold {
  background: var(--accent);
  color: #0a0908;
}

.btn-gold:hover:not(:disabled) {
  background: #fff;
}

.btn-gold:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.btn-light {
  background: #fff;
  color: #0a0908;
}

.btn-light:hover {
  background: var(--accent);
}

.btn-ghost {
  border: 1px solid rgb(255 255 255 / 0.22);
  color: rgb(255 255 255 / 0.75);
}

.btn-ghost:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.btn-text {
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgb(255 255 255 / 0.45);
  transition: color 0.3s ease;
}

.btn-text:hover {
  color: #fff;
}

/* Calendar */
.cal-nav {
  display: flex;
  height: 2.25rem;
  width: 2.25rem;
  align-items: center;
  justify-content: center;
  border: 1px solid rgb(255 255 255 / 0.12);
  color: rgb(255 255 255 / 0.6);
  font-size: 1.1rem;
  line-height: 1;
  transition:
    border-color 0.3s ease,
    color 0.3s ease;
}

.cal-nav:hover:not(:disabled) {
  border-color: var(--accent);
  color: var(--accent);
}

.cal-nav:disabled {
  opacity: 0.25;
  cursor: not-allowed;
}

.cal-day {
  margin-inline: auto;
  display: flex;
  height: 2.6rem;
  width: 2.6rem;
  align-items: center;
  justify-content: center;
  font-size: 0.9rem;
  font-variant-numeric: tabular-nums;
  color: rgb(255 255 255 / 0.8);
  transition:
    background-color 0.25s ease,
    color 0.25s ease;
}

.cal-day:hover:not(:disabled) {
  background: rgb(255 255 255 / 0.07);
}

.cal-day-on,
.cal-day-on:hover {
  background: var(--accent);
  color: #0a0908;
}

.cal-day-off {
  color: rgb(255 255 255 / 0.2);
  cursor: not-allowed;
}

/* Time chips */
.slot {
  border: 1px solid rgb(255 255 255 / 0.1);
  padding: 0.7rem 0.4rem;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  color: rgb(255 255 255 / 0.8);
  transition:
    border-color 0.25s ease,
    background-color 0.25s ease,
    color 0.25s ease;
}

.slot:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.slot-on,
.slot-on:hover {
  border-color: var(--accent);
  background: var(--accent);
  color: #0a0908;
}

/* Summary / detail rows */
.summary {
  border: 1px solid rgb(255 255 255 / 0.08);
  background: #0e0d0c;
  padding: 1.5rem;
  font-size: 0.9rem;
}

.summary > div {
  display: flex;
  justify-content: space-between;
  gap: 1.5rem;
  padding-block: 0.5rem;
}

.summary dt {
  color: rgb(255 255 255 / 0.4);
}

.summary dd {
  text-align: right;
  color: #fff;
}

/* Beats `.summary dd` on specificity, which a utility class would not. */
.summary dd.dd-accent {
  color: var(--accent);
}

.summary-total {
  border-top: 1px solid rgb(255 255 255 / 0.08);
  margin-top: 0.5rem;
  padding-top: 1rem !important;
}

/* Form fields */
/* Who the booking is for, when the visitor is signed in. */
.identity {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.2rem 0.75rem;
  border-left: 1px solid var(--accent);
  background: rgb(255 255 255 / 0.02);
  padding: 0.85rem 1.1rem;
}

.identity-label {
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgb(255 255 255 / 0.5);
}

.identity-name {
  color: #fff;
}

.identity-email {
  font-size: 0.85rem;
  color: rgb(255 255 255 / 0.4);
}

.field-label {
  display: block;
  margin-bottom: 0.6rem;
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgb(255 255 255 / 0.5);
}

.field {
  width: 100%;
  border: 1px solid rgb(255 255 255 / 0.12);
  background: #0a0908;
  padding: 0.9rem 1rem;
  color: #fff;
  outline: none;
  transition:
    border-color 0.25s ease,
    background-color 0.25s ease;
}

.field::placeholder {
  color: rgb(255 255 255 / 0.25);
}

.field:focus {
  border-color: var(--accent);
  background: #0e0d0c;
}

/* Chrome paints autofilled inputs pale blue; keep the room dark. */
.field:-webkit-autofill,
.field:-webkit-autofill:hover,
.field:-webkit-autofill:focus {
  -webkit-text-fill-color: #fff;
  -webkit-box-shadow: 0 0 0 60rem #0a0908 inset;
  caret-color: #fff;
}

.field-error {
  margin-top: 0.5rem;
  font-size: 0.85rem;
  color: #f2a0a0;
}

.method {
  display: flex;
  cursor: pointer;
  align-items: center;
  gap: 0.65rem;
  border: 1px solid rgb(255 255 255 / 0.12);
  padding: 0.8rem 1rem;
  font-size: 0.9rem;
  color: rgb(255 255 255 / 0.7);
  transition:
    border-color 0.25s ease,
    color 0.25s ease;
}

.method-on {
  border-color: var(--accent);
  color: #fff;
}

.alert-error {
  border: 1px solid rgb(242 160 160 / 0.35);
  background: rgb(242 160 160 / 0.07);
  padding: 0.9rem 1.1rem;
  font-size: 0.9rem;
  color: #f2a0a0;
}

.empty {
  border: 1px solid rgb(255 255 255 / 0.08);
  background: #0e0d0c;
  padding: 2.5rem 1.5rem;
  text-align: center;
  font-size: 0.9rem;
  color: rgb(255 255 255 / 0.4);
}

/* One brass ring, used wherever something is loading. */
.spinner {
  display: inline-block;
  height: 1.5rem;
  width: 1.5rem;
  border: 1px solid rgb(255 255 255 / 0.15);
  border-top-color: var(--accent);
  border-radius: 9999px;
  animation: spin 0.8s linear infinite;
}

.spinner-sm {
  height: 0.9rem;
  width: 0.9rem;
  border-top-color: #0a0908;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
