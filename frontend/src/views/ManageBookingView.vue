<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
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

function formatTime(t) {
  if (!t) return ''
  const [h, min] = t.split(':').map(Number)
  if (Number.isNaN(h)) return t
  const period = h < 12 ? 'AM' : 'PM'
  const hour12 = h % 12 === 0 ? 12 : h % 12
  return `${hour12}:${String(min ?? 0).padStart(2, '0')} ${period}`
}

// One dark room, so a status reads as a tone rather than a coloured pill:
// live bookings wear the salon's brass, closed ones fade out.
const STATUS_META = {
  pending: { label: 'Pending', tone: 'accent' },
  confirmed: { label: 'Confirmed', tone: 'accent' },
  completed: { label: 'Completed', tone: 'good' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  no_show: { label: 'No-show', tone: 'bad' },
}
function statusLabel(s) {
  return STATUS_META[s]?.label || s || '—'
}
function statusTone(s) {
  return STATUS_META[s]?.tone || 'muted'
}

/* ------------------------------ Load booking ------------------------------ */
const booking = ref(null)
const loading = ref(true)
const notFound = ref(false)
const loadError = ref('')

// An untouched salon keeps the brass rather than the API's indigo placeholder.
const accent = computed(() => {
  const chosen = booking.value?.salon?.theme_color
  return !chosen || chosen.toLowerCase() === '#6366f1' ? '#c8a45d' : chosen
})

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
    const { data } = await api.get(`${apiBase}/manage/${token}`)
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

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']

// First of the month currently drawn in the calendar.
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
  rescheduleDate.value = cell.value
}

watch(rescheduleDate, () => {
  if (rescheduling.value) loadSlots()
})

function openReschedule() {
  actionMessage.value = ''
  rescheduling.value = true
  rescheduleDate.value = booking.value?.date || todayStr()
  // Open the calendar on the month the booking already sits in.
  const [y, m] = rescheduleDate.value.split('-').map(Number)
  calendar.year = y
  calendar.month = m - 1
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
    const { data } = await api.get(`${apiBase}/slots`, {
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
    const { data } = await api.post(`${apiBase}/manage/${token}/reschedule`, {
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
    const { data } = await api.post(`${apiBase}/manage/${token}/cancel`)
    booking.value = data.data
    confirmingCancel.value = false
  } catch (err) {
    actionMessage.value = parseApiError(err, 'Could not cancel this booking.').message
    confirmingCancel.value = false
  } finally {
    cancelling.value = false
  }
}

/* ------------------------------ Review ------------------------------ */
const reviewRating = ref(0)
const reviewHover = ref(0)
const reviewComment = ref('')
const submittingReview = ref(false)
const reviewError = ref('')

const STAR = 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z'

async function submitReview() {
  if (!reviewRating.value) return
  submittingReview.value = true
  reviewError.value = ''
  try {
    const { data } = await api.post(`${apiBase}/manage/${token}/review`, {
      rating: reviewRating.value,
      comment: reviewComment.value || null,
    })
    // Reflect the new review in place so the form gives way to a thank-you.
    booking.value.review = data.data
    booking.value.can_review = false
  } catch (err) {
    reviewError.value = parseApiError(err, 'Could not submit your review.').message
  } finally {
    submittingReview.value = false
  }
}

const bookAnotherLink = computed(() => `/book/${slug}`)

onMounted(loadBooking)
</script>

<template>
  <div class="manage" :style="{ '--accent': accent }">
    <!-- Loading -->
    <div v-if="loading" class="flex min-h-screen items-center justify-center px-6">
      <div class="text-center">
        <span class="spinner" />
        <p class="label mt-4 text-white/40">Loading your booking</p>
      </div>
    </div>

    <!-- Not found / invalid link -->
    <div v-else-if="notFound" class="flex min-h-screen items-center justify-center px-6">
      <div class="panel w-full max-w-md p-10 text-center">
        <h1 class="font-display text-3xl text-white">Booking not found</h1>
        <p class="mt-3 text-sm leading-relaxed text-white/45">
          This management link is invalid or has expired. Please check the link from your confirmation.
        </p>
      </div>
    </div>

    <!-- Hard error -->
    <div v-else-if="loadError" class="flex min-h-screen items-center justify-center px-6">
      <div class="panel w-full max-w-md p-10 text-center">
        <h1 class="font-display text-3xl text-white">Something went wrong</h1>
        <p class="mt-3 text-sm text-white/45">{{ loadError }}</p>
        <button type="button" class="btn-gold mt-7" @click="loadBooking">Try again</button>
      </div>
    </div>

    <!-- Booking -->
    <div v-else-if="booking" class="relative min-h-screen">
      <div class="absolute inset-x-0 top-0 -z-10 h-[17rem] bg-[radial-gradient(110%_100%_at_50%_0%,#231e18_0%,#080706_72%)]" />

      <RouterLink :to="bookAnotherLink" class="label absolute top-7 left-6 z-10 text-white/55 transition hover:text-white lg:left-10">
        ← Back to booking
      </RouterLink>

      <header class="px-6 pt-24 pb-14 text-center">
        <p class="rule-label justify-center text-[var(--accent)]">Manage your booking</p>
        <h1 class="mt-5 font-display text-[clamp(2.2rem,6vw,3.4rem)] leading-tight text-white">
          {{ booking.salon?.name }}
        </h1>
      </header>

      <main class="mx-auto w-full max-w-2xl px-6 pb-20">
        <!-- Online-deposit outcome, shown when returning from the gateway. -->
        <div v-if="paymentOutcome" class="mb-5" :class="paymentOutcome.tone === 'ok' ? 'alert-ok' : 'alert-error'">
          {{ paymentOutcome.text }}
        </div>

        <div class="panel p-6 sm:p-9">
          <!-- Status + summary -->
          <div class="flex items-baseline justify-between gap-4">
            <h2 class="font-display text-2xl text-white">Appointment details</h2>
            <span class="label" :class="`tone-${statusTone(booking.status)}`">{{ statusLabel(booking.status) }}</span>
          </div>

          <dl class="summary mt-7">
            <div>
              <dt>Date</dt>
              <dd>{{ formatDate(booking.date) }}</dd>
            </div>
            <div>
              <dt>Time</dt>
              <dd>
                {{ formatTime(booking.start_time) }}<template v-if="booking.end_time"> – {{ formatTime(booking.end_time) }}</template>
              </dd>
            </div>
            <div>
              <dt>Service</dt>
              <dd>{{ booking.service?.name }}</dd>
            </div>
            <div>
              <dt>Professional</dt>
              <dd>{{ booking.staff?.name }}</dd>
            </div>
            <div v-if="booking.branch?.name">
              <dt>Location</dt>
              <dd>{{ booking.branch.name }}</dd>
            </div>
          </dl>

          <div v-if="actionMessage" class="alert-error mt-5">{{ actionMessage }}</div>

          <!-- No-longer-changeable notice -->
          <p v-if="!booking.changeable" class="empty mt-7">
            This booking is
            <span class="text-white">{{ statusLabel(booking.status).toLowerCase() }}</span>
            and can no longer be changed.
          </p>

          <!-- Actions -->
          <div v-else class="mt-8">
            <!-- Reschedule panel -->
            <div v-if="rescheduling">
              <p class="rule-label text-[var(--accent)]">Pick a new date &amp; time</p>

              <div class="mt-7">
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
                        cell.value === rescheduleDate ? 'cal-day-on' : '',
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
                <p class="rule-label text-white/35">Available — {{ formatDateShort(rescheduleDate) }}</p>
                <div class="mt-5 grid grid-cols-3 gap-2.5 sm:grid-cols-5">
                  <button
                    v-for="slot in slots"
                    :key="slot"
                    type="button"
                    class="slot"
                    :class="selectedSlot === slot ? 'slot-on' : ''"
                    @click="selectedSlot = slot"
                  >
                    {{ formatTime(slot) }}
                  </button>
                </div>
              </div>

              <div class="mt-10 flex items-center justify-between gap-4">
                <button type="button" class="btn-text" @click="closeReschedule">← Back</button>
                <button
                  type="button"
                  :disabled="!selectedSlot || savingReschedule"
                  class="btn-gold"
                  @click="confirmReschedule"
                >
                  <span v-if="savingReschedule" class="spinner spinner-sm" />
                  {{ savingReschedule ? 'Saving…' : 'Confirm new time' }}
                </button>
              </div>
            </div>

            <!-- Cancel confirm -->
            <div v-else-if="confirmingCancel" class="border border-[#c46b6b]/35 bg-[#c46b6b]/6 p-6">
              <p class="font-display text-xl text-white">Cancel this appointment?</p>
              <p class="mt-2 text-sm text-white/45">This frees your slot and can't be undone.</p>
              <div class="mt-6 flex items-center justify-end gap-3">
                <button type="button" class="btn-ghost" @click="confirmingCancel = false">Keep it</button>
                <button type="button" :disabled="cancelling" class="btn-danger" @click="confirmCancel">
                  <span v-if="cancelling" class="spinner spinner-sm" />
                  {{ cancelling ? 'Cancelling…' : 'Yes, cancel' }}
                </button>
              </div>
            </div>

            <!-- Default action buttons -->
            <div v-else class="flex flex-col gap-3 sm:flex-row">
              <button type="button" class="btn-gold flex-1" @click="openReschedule">Reschedule</button>
              <button type="button" class="btn-ghost flex-1" @click="confirmingCancel = true">Cancel booking</button>
            </div>
          </div>
        </div>

        <!-- Already reviewed: show a thank-you with the rating they left. -->
        <div v-if="booking.review" class="panel mt-5 p-6 sm:p-9">
          <h2 class="font-display text-2xl text-white">Your review</h2>
          <div class="mt-4 flex gap-1 text-[var(--accent)]">
            <svg
              v-for="star in 5"
              :key="star"
              class="h-5 w-5"
              :fill="star <= booking.review.rating ? 'currentColor' : 'none'"
              viewBox="0 0 24 24"
              stroke-width="1.2"
              stroke="currentColor"
            >
              <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
            </svg>
          </div>
          <p v-if="booking.review.comment" class="mt-5 leading-relaxed text-white/65">{{ booking.review.comment }}</p>
          <p class="mt-5 text-sm text-white/35">Thanks for your feedback.</p>
        </div>

        <!-- Reviewable completed booking: invite a rating. -->
        <div v-else-if="booking.can_review" class="panel mt-5 p-6 sm:p-9">
          <h2 class="font-display text-2xl text-white">How was your visit?</h2>
          <p class="mt-2 text-sm text-white/45">Leave a rating to help others find great service.</p>

          <div class="mt-6 flex gap-1.5" @mouseleave="reviewHover = 0">
            <button
              v-for="star in 5"
              :key="star"
              type="button"
              class="text-[var(--accent)] transition hover:scale-110"
              @mouseenter="reviewHover = star"
              @click="reviewRating = star"
            >
              <svg
                class="h-8 w-8"
                :fill="star <= (reviewHover || reviewRating) ? 'currentColor' : 'none'"
                viewBox="0 0 24 24"
                stroke-width="1.2"
                stroke="currentColor"
              >
                <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
              </svg>
            </button>
          </div>

          <label class="field-label mt-7">Your comment <span class="text-white/30">(optional)</span></label>
          <textarea
            v-model="reviewComment"
            rows="4"
            maxlength="1000"
            placeholder="Tell us about your experience"
            class="field"
          ></textarea>

          <div v-if="reviewError" class="alert-error mt-4">{{ reviewError }}</div>

          <button
            type="button"
            :disabled="!reviewRating || submittingReview"
            class="btn-gold mt-6"
            @click="submitReview"
          >
            <span v-if="submittingReview" class="spinner spinner-sm" />
            {{ submittingReview ? 'Submitting…' : 'Submit review' }}
          </button>
        </div>

        <p class="mt-9 text-center text-sm">
          <a :href="bookAnotherLink" class="text-[var(--accent)] underline underline-offset-4 transition hover:text-white">
            Book another appointment
          </a>
        </p>
        <p class="label mt-4 text-center text-white/25">Powered by SalonHub</p>
      </main>
    </div>
  </div>
</template>

<style scoped>
/*
 * Same dark room as the booking wizard and the salon's own site — a customer
 * who follows the "manage this booking" link should not feel they left.
 */
.manage {
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

/* Eyebrow with a brass rule on each side when centred. */
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

/* Status tones — beat nothing, so plain classes are enough. */
.tone-accent {
  color: var(--accent);
}

.tone-good {
  color: #8fbf9a;
}

.tone-bad {
  color: #f2a0a0;
}

.tone-muted {
  color: rgb(255 255 255 / 0.35);
}

.btn-gold,
.btn-ghost,
.btn-light,
.btn-danger {
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

.btn-ghost {
  border: 1px solid rgb(255 255 255 / 0.22);
  color: rgb(255 255 255 / 0.75);
}

.btn-ghost:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.btn-danger {
  background: #c46b6b;
  color: #0a0908;
}

.btn-danger:hover:not(:disabled) {
  background: #f2a0a0;
}

.btn-danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

/* Detail rows */
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

/* Form fields */
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

.alert-error {
  border: 1px solid rgb(242 160 160 / 0.35);
  background: rgb(242 160 160 / 0.07);
  padding: 0.9rem 1.1rem;
  font-size: 0.9rem;
  color: #f2a0a0;
}

.alert-ok {
  border: 1px solid rgb(143 191 154 / 0.35);
  background: rgb(143 191 154 / 0.07);
  padding: 0.9rem 1.1rem;
  font-size: 0.9rem;
  color: #8fbf9a;
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
