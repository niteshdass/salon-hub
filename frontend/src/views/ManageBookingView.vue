<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'

const route = useRoute()
const slug = route.params.slug
const token = route.params.token

/* ------------------------------ Helpers ------------------------------ */
function todayStr() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-').map(Number)
  if (!y || !m || !d) return dateStr
  return new Date(y, m - 1, d).toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  })
}

function formatTime(t) {
  if (!t) return ''
  const [h, min] = t.split(':').map(Number)
  if (Number.isNaN(h)) return t
  const period = h < 12 ? 'AM' : 'PM'
  const hour12 = h % 12 === 0 ? 12 : h % 12
  return `${hour12}:${String(min ?? 0).padStart(2, '0')} ${period}`
}

const STATUS_META = {
  pending: { label: 'Pending', badge: 'bg-amber-100 text-amber-700' },
  confirmed: { label: 'Confirmed', badge: 'bg-blue-100 text-blue-700' },
  completed: { label: 'Completed', badge: 'bg-emerald-100 text-emerald-700' },
  cancelled: { label: 'Cancelled', badge: 'bg-slate-200 text-slate-600' },
  no_show: { label: 'No-show', badge: 'bg-rose-100 text-rose-700' },
}
function statusLabel(s) {
  return STATUS_META[s]?.label || s || '—'
}
function statusBadge(s) {
  return STATUS_META[s]?.badge || 'bg-slate-200 text-slate-600'
}

/* ------------------------------ Load booking ------------------------------ */
const booking = ref(null)
const loading = ref(true)
const notFound = ref(false)
const loadError = ref('')

// Outcome of an online deposit the customer just returned from paying. The
// gateway sends them back here with ?payment=success|failed|cancelled.
const PAYMENT_OUTCOME = {
  success: { tone: 'ok', text: 'Payment received — your deposit is confirmed. Thank you!' },
  failed: { tone: 'bad', text: 'That payment did not go through. Your booking is held; you can try paying again.' },
  cancelled: { tone: 'bad', text: 'Payment was cancelled. Your booking is held but the deposit is still unpaid.' },
  error: { tone: 'bad', text: 'We could not confirm that payment. Please contact the salon.' },
}
const paymentOutcome = computed(() => PAYMENT_OUTCOME[route.query.payment] || null)

async function loadBooking() {
  loading.value = true
  loadError.value = ''
  notFound.value = false
  try {
    const { data } = await api.get(`/public/${slug}/manage/${token}`)
    booking.value = data.data
  } catch (err) {
    if (err?.response?.status === 404) {
      notFound.value = true
    } else {
      loadError.value = parseApiError(err, 'Could not load this booking.').message
    }
  } finally {
    loading.value = false
  }
}

/* ------------------------------ Reschedule ------------------------------ */
const rescheduling = ref(false) // panel open
const rescheduleDate = ref(todayStr())
const slots = ref([])
const slotsLoading = ref(false)
const slotsError = ref('')
const slotsLoaded = ref(false)
const selectedSlot = ref('')
const savingReschedule = ref(false)
const actionMessage = ref('')

function openReschedule() {
  actionMessage.value = ''
  rescheduling.value = true
  rescheduleDate.value = booking.value?.date || todayStr()
  selectedSlot.value = ''
  loadSlots()
}

function closeReschedule() {
  rescheduling.value = false
}

async function loadSlots() {
  if (!booking.value?.service?.id || !booking.value?.staff?.id) return
  slotsLoading.value = true
  slotsError.value = ''
  selectedSlot.value = ''
  try {
    const { data } = await api.get(`/public/${slug}/slots`, {
      params: {
        service_id: booking.value.service.id,
        staff_id: booking.value.staff.id,
        date: rescheduleDate.value,
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

async function confirmReschedule() {
  if (!selectedSlot.value) return
  savingReschedule.value = true
  actionMessage.value = ''
  try {
    const { data } = await api.post(`/public/${slug}/manage/${token}/reschedule`, {
      date: rescheduleDate.value,
      start_time: selectedSlot.value,
    })
    booking.value = data.data
    rescheduling.value = false
  } catch (err) {
    const parsed = parseApiError(err)
    actionMessage.value = parsed.message || 'Could not reschedule. Please try another time.'
    // The chosen slot may have just been taken — refresh the grid.
    await loadSlots()
  } finally {
    savingReschedule.value = false
  }
}

/* ------------------------------ Cancel ------------------------------ */
const confirmingCancel = ref(false)
const cancelling = ref(false)

async function confirmCancel() {
  cancelling.value = true
  actionMessage.value = ''
  try {
    const { data } = await api.post(`/public/${slug}/manage/${token}/cancel`)
    booking.value = data.data
    confirmingCancel.value = false
  } catch (err) {
    actionMessage.value = parseApiError(err, 'Could not cancel this booking.').message
    confirmingCancel.value = false
  } finally {
    cancelling.value = false
  }
}

const bookAnotherLink = computed(() => `/book/${slug}`)

onMounted(loadBooking)
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <!-- Loading -->
    <div v-if="loading" class="flex min-h-screen items-center justify-center px-4">
      <div class="text-center">
        <svg class="mx-auto h-7 w-7 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <p class="mt-3 text-sm text-slate-500">Loading your booking…</p>
      </div>
    </div>

    <!-- Not found / invalid link -->
    <div v-else-if="notFound" class="flex min-h-screen items-center justify-center px-4">
      <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100">
          <svg class="h-7 w-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h1 class="mt-4 text-lg font-semibold text-slate-900">Booking not found</h1>
        <p class="mt-1 text-sm text-slate-500">
          This management link is invalid or has expired. Please check the link from your confirmation.
        </p>
      </div>
    </div>

    <!-- Hard error -->
    <div v-else-if="loadError" class="flex min-h-screen items-center justify-center px-4">
      <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-semibold text-slate-900">Something went wrong</h1>
        <p class="mt-1 text-sm text-slate-500">{{ loadError }}</p>
        <button
          type="button"
          class="mt-5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
          @click="loadBooking"
        >
          Try again
        </button>
      </div>
    </div>

    <!-- Booking -->
    <div v-else-if="booking">
      <header class="bg-gradient-to-b from-indigo-600 to-indigo-700 px-4 py-10 text-center text-white sm:py-12">
        <p class="text-xs font-medium uppercase tracking-widest text-indigo-200">Manage your booking</p>
        <h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ booking.salon?.name }}</h1>
      </header>

      <main class="mx-auto -mt-6 w-full max-w-lg px-4 pb-16">
        <!-- Online-deposit outcome, shown when returning from the gateway. -->
        <div
          v-if="paymentOutcome"
          class="mb-4 rounded-xl px-4 py-3 text-sm font-medium ring-1"
          :class="paymentOutcome.tone === 'ok'
            ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
            : 'bg-rose-50 text-rose-700 ring-rose-200'"
        >
          {{ paymentOutcome.text }}
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-7">
          <!-- Status + summary -->
          <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-slate-900">Appointment details</h2>
            <span
              class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
              :class="statusBadge(booking.status)"
            >
              {{ statusLabel(booking.status) }}
            </span>
          </div>

          <dl class="mt-5 space-y-2 rounded-xl bg-slate-50 p-4 text-sm">
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">Date</dt>
              <dd class="text-right font-medium text-slate-900">{{ formatDate(booking.date) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">Time</dt>
              <dd class="text-right font-medium text-slate-900">
                {{ formatTime(booking.start_time) }}<template v-if="booking.end_time"> – {{ formatTime(booking.end_time) }}</template>
              </dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">Service</dt>
              <dd class="text-right font-medium text-slate-900">{{ booking.service?.name }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-slate-500">Professional</dt>
              <dd class="text-right font-medium text-slate-900">{{ booking.staff?.name }}</dd>
            </div>
            <div v-if="booking.branch?.name" class="flex justify-between gap-4">
              <dt class="text-slate-500">Location</dt>
              <dd class="text-right font-medium text-slate-900">{{ booking.branch.name }}</dd>
            </div>
          </dl>

          <div
            v-if="actionMessage"
            class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
          >
            {{ actionMessage }}
          </div>

          <!-- No-longer-changeable notice -->
          <p
            v-if="!booking.changeable"
            class="mt-5 rounded-lg bg-slate-50 px-4 py-4 text-center text-sm text-slate-500"
          >
            This booking is <span class="font-medium">{{ statusLabel(booking.status).toLowerCase() }}</span> and can no longer be changed.
          </p>

          <!-- Actions -->
          <div v-else class="mt-6">
            <!-- Reschedule panel -->
            <div v-if="rescheduling" class="rounded-xl border border-slate-200 p-4">
              <h3 class="text-sm font-semibold text-slate-900">Pick a new date &amp; time</h3>

              <div class="mt-3">
                <label class="mb-1 block text-xs font-medium text-slate-500">Date</label>
                <input
                  v-model="rescheduleDate"
                  type="date"
                  :min="todayStr()"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 sm:w-auto"
                  @change="loadSlots"
                />
              </div>

              <div v-if="slotsLoading" class="py-8 text-center">
                <svg class="mx-auto h-5 w-5 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                <p class="mt-2 text-sm text-slate-500">Finding open times…</p>
              </div>

              <div v-else-if="slotsError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ slotsError }}
                <button type="button" class="ml-2 font-medium underline" @click="loadSlots">Retry</button>
              </div>

              <p v-else-if="slots.length === 0" class="mt-4 rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                No open times on this day, try another date.
              </p>

              <div v-else class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4">
                <button
                  v-for="slot in slots"
                  :key="slot"
                  type="button"
                  class="rounded-lg border px-2 py-2.5 text-sm font-medium transition"
                  :class="selectedSlot === slot
                    ? 'border-indigo-500 bg-indigo-600 text-white'
                    : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300 hover:bg-indigo-50'"
                  @click="selectedSlot = slot"
                >
                  {{ formatTime(slot) }}
                </button>
              </div>

              <div class="mt-5 flex items-center justify-between gap-3">
                <button type="button" class="text-sm font-medium text-slate-500 transition hover:text-slate-700" @click="closeReschedule">
                  Cancel
                </button>
                <button
                  type="button"
                  :disabled="!selectedSlot || savingReschedule"
                  class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                  @click="confirmReschedule"
                >
                  <svg v-if="savingReschedule" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  {{ savingReschedule ? 'Saving…' : 'Confirm new time' }}
                </button>
              </div>
            </div>

            <!-- Cancel confirm -->
            <div v-else-if="confirmingCancel" class="rounded-xl border border-rose-200 bg-rose-50 p-4">
              <p class="text-sm font-medium text-rose-800">Cancel this appointment?</p>
              <p class="mt-1 text-sm text-rose-600">This frees your slot and can't be undone.</p>
              <div class="mt-4 flex items-center justify-end gap-3">
                <button
                  type="button"
                  class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
                  @click="confirmingCancel = false"
                >
                  Keep it
                </button>
                <button
                  type="button"
                  :disabled="cancelling"
                  class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                  @click="confirmCancel"
                >
                  <svg v-if="cancelling" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  {{ cancelling ? 'Cancelling…' : 'Yes, cancel' }}
                </button>
              </div>
            </div>

            <!-- Default action buttons -->
            <div v-else class="flex flex-col gap-3 sm:flex-row">
              <button
                type="button"
                class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
                @click="openReschedule"
              >
                Reschedule
              </button>
              <button
                type="button"
                class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white px-4 py-2.5 text-sm font-medium text-rose-600 shadow-sm transition hover:bg-rose-50"
                @click="confirmingCancel = true"
              >
                Cancel booking
              </button>
            </div>
          </div>
        </div>

        <p class="mt-6 text-center text-sm">
          <a :href="bookAnotherLink" class="font-medium text-indigo-600 hover:text-indigo-700">Book another appointment</a>
        </p>
        <p class="mt-3 text-center text-xs text-slate-400">Powered by SalonHub</p>
      </main>
    </div>
  </div>
</template>
