<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import customerApi from '@/lib/customerApi'

const loading = ref(true)
const error = ref('')
const upcoming = ref([])
const past = ref([])

// Reschedule modal state.
const rescheduling = ref(null) // booking object
const rDate = ref('')
const rSlots = ref([])
const rSlot = ref('')
const rLoadingSlots = ref(false)
const rError = ref('')

// Review modal state.
const reviewing = ref(null)
const vRating = ref(5)
const vComment = ref('')
const vError = ref('')

const STAR = 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z'

// Prices belong to the salon that set them, and a customer can hold bookings
// at salons using different currencies.
function money(value, booking) {
  const num = Number(value)
  if (Number.isNaN(num)) return value
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: booking?.salon?.currency || 'USD',
      maximumFractionDigits: 2,
    }).format(num)
  } catch {
    return num.toFixed(2)
  }
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-').map(Number)
  if (!y || !m || !d) return dateStr
  return new Date(y, m - 1, d).toLocaleDateString(undefined, {
    weekday: 'short',
    month: 'short',
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

// Live bookings wear the salon's brass; closed ones fade out.
const STATUS_TONE = {
  pending: 'accent',
  confirmed: 'accent',
  completed: 'good',
  cancelled: 'muted',
  no_show: 'bad',
}
function statusTone(s) {
  return STATUS_TONE[s] || 'muted'
}
function statusLabel(s) {
  return (s || '').replace('_', '-')
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await customerApi.get('/customer/bookings')
    upcoming.value = data.data.upcoming
    past.value = data.data.past
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your bookings.'
  } finally {
    loading.value = false
  }
}

async function cancel(booking) {
  if (!window.confirm('Cancel this booking?')) return
  try {
    await customerApi.post(`/customer/bookings/${booking.id}/cancel`)
    await load()
  } catch (e) {
    window.alert(e.response?.data?.message || 'Could not cancel.')
  }
}

function openReschedule(booking) {
  rescheduling.value = booking
  rDate.value = booking.booking_date
  rSlots.value = []
  rSlot.value = ''
  rError.value = ''
  loadSlots()
}

async function loadSlots() {
  if (!rDate.value) return
  rLoadingSlots.value = true
  rError.value = ''
  rSlot.value = ''
  try {
    const { data } = await customerApi.get(`/customer/bookings/${rescheduling.value.id}/slots`, { params: { date: rDate.value } })
    rSlots.value = data.data.slots
  } catch (e) {
    rError.value = e.response?.data?.message || 'Could not load slots.'
  } finally {
    rLoadingSlots.value = false
  }
}

async function submitReschedule() {
  rError.value = ''
  try {
    await customerApi.post(`/customer/bookings/${rescheduling.value.id}/reschedule`, { date: rDate.value, start_time: rSlot.value })
    rescheduling.value = null
    await load()
  } catch (e) {
    rError.value = e.response?.data?.message || 'Could not reschedule.'
  }
}

function openReview(booking) {
  reviewing.value = booking
  vRating.value = 5
  vComment.value = ''
  vError.value = ''
}

async function submitReview() {
  vError.value = ''
  try {
    await customerApi.post(`/customer/bookings/${reviewing.value.id}/review`, { rating: vRating.value, comment: vComment.value || null })
    reviewing.value = null
    await load()
  } catch (e) {
    vError.value = e.response?.data?.message || 'Could not submit review.'
  }
}

onMounted(load)
</script>

<template>
  <div class="customer-bookings">
    <div v-if="loading" class="py-20 text-center">
      <span class="spinner" />
      <p class="label mt-4 text-white/40">Loading</p>
    </div>

    <p v-else-if="error" class="alert-error">{{ error }}</p>

    <template v-else>
      <section>
        <p class="rule-label text-[var(--accent)]">Upcoming</p>

        <p v-if="!upcoming.length" class="empty mt-6">No upcoming bookings.</p>

        <ul v-else class="mt-6 space-y-4">
          <li v-for="b in upcoming" :key="b.id" class="card p-6">
            <div class="flex items-start justify-between gap-5">
              <div class="min-w-0">
                <p class="font-display text-xl text-white">
                  {{ b.services.join(', ') }} <span class="text-white/25">·</span>
                  <RouterLink v-if="b.salon.slug" :to="`/salon/${b.salon.slug}`" class="salon-link">
                    {{ b.salon.name }}
                  </RouterLink>
                  <template v-else>{{ b.salon.name }}</template>
                </p>
                <p class="mt-2 text-sm text-white/45">
                  {{ formatDate(b.booking_date) }} at {{ formatTime(b.start_time) }} · {{ b.staff }} · {{ b.branch }}
                </p>
                <p class="mt-1.5 text-sm text-white/35">
                  {{ money(b.price, b) }} · paid {{ money(b.amount_paid, b) }} · due
                  <span class="text-white/60">{{ money(b.balance_due, b) }}</span>
                </p>
              </div>
              <span class="label shrink-0" :class="`tone-${statusTone(b.status)}`">{{ statusLabel(b.status) }}</span>
            </div>

            <div v-if="b.can_manage" class="mt-6 flex flex-wrap gap-3">
              <button type="button" class="btn-ghost" @click="openReschedule(b)">Reschedule</button>
              <button type="button" class="btn-ghost btn-ghost-danger" @click="cancel(b)">Cancel</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="mt-14">
        <p class="rule-label text-white/35">Past</p>

        <p v-if="!past.length" class="empty mt-6">No past bookings.</p>

        <ul v-else class="mt-6 space-y-4">
          <li v-for="b in past" :key="b.id" class="card p-6">
            <div class="flex items-start justify-between gap-5">
              <div class="min-w-0">
                <p class="font-display text-xl text-white">
                  {{ b.services.join(', ') }} <span class="text-white/25">·</span>
                  <RouterLink v-if="b.salon.slug" :to="`/salon/${b.salon.slug}`" class="salon-link">
                    {{ b.salon.name }}
                  </RouterLink>
                  <template v-else>{{ b.salon.name }}</template>
                </p>
                <p class="mt-2 text-sm text-white/45">
                  {{ formatDate(b.booking_date) }} at {{ formatTime(b.start_time) }} · {{ b.staff }}
                </p>
              </div>
              <span class="label shrink-0" :class="`tone-${statusTone(b.status)}`">{{ statusLabel(b.status) }}</span>
            </div>

            <div v-if="b.review" class="mt-5">
              <div class="flex gap-1 text-[var(--accent)]">
                <svg
                  v-for="star in 5"
                  :key="star"
                  class="h-4 w-4"
                  :fill="star <= b.review.rating ? 'currentColor' : 'none'"
                  viewBox="0 0 24 24"
                  stroke-width="1.2"
                  stroke="currentColor"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
                </svg>
              </div>
              <p v-if="b.review.comment" class="mt-2.5 text-sm leading-relaxed text-white/50">{{ b.review.comment }}</p>
            </div>

            <button v-else-if="b.can_review" type="button" class="btn-ghost mt-5" @click="openReview(b)">
              Leave review
            </button>
          </li>
        </ul>
      </section>
    </template>

    <!-- Reschedule modal -->
    <div v-if="rescheduling" class="scrim" @click.self="rescheduling = null">
      <div class="panel w-full max-w-md p-7">
        <p class="rule-label text-[var(--accent)]">Reschedule</p>
        <p class="mt-4 font-display text-2xl text-white">{{ rescheduling.services.join(', ') }}</p>

        <label class="field-label mt-6">Date</label>
        <input v-model="rDate" type="date" class="field" @change="loadSlots" />

        <p v-if="rError" class="alert-error mt-4">{{ rError }}</p>

        <div v-if="rLoadingSlots" class="py-10 text-center">
          <span class="spinner" />
          <p class="label mt-4 text-white/40">Finding open times</p>
        </div>

        <template v-else>
          <p v-if="!rSlots.length" class="empty mt-5">No open slots on this day.</p>
          <div v-else class="mt-5 grid max-h-52 grid-cols-3 gap-2.5 overflow-y-auto">
            <button
              v-for="s in rSlots"
              :key="s"
              type="button"
              class="slot"
              :class="rSlot === s ? 'slot-on' : ''"
              @click="rSlot = s"
            >
              {{ formatTime(s) }}
            </button>
          </div>
        </template>

        <div class="mt-7 flex items-center justify-between gap-4">
          <button type="button" class="btn-text" @click="rescheduling = null">Close</button>
          <button type="button" class="btn-gold" :disabled="!rSlot" @click="submitReschedule">Confirm</button>
        </div>
      </div>
    </div>

    <!-- Review modal -->
    <div v-if="reviewing" class="scrim" @click.self="reviewing = null">
      <div class="panel w-full max-w-md p-7">
        <p class="rule-label text-[var(--accent)]">Leave a review</p>
        <p class="mt-4 font-display text-2xl text-white">{{ reviewing.services.join(', ') }}</p>

        <label class="field-label mt-6">Rating</label>
        <div class="flex gap-1.5">
          <button
            v-for="n in 5"
            :key="n"
            type="button"
            class="text-[var(--accent)] transition hover:scale-110"
            :aria-label="`${n} star${n > 1 ? 's' : ''}`"
            @click="vRating = n"
          >
            <svg
              class="h-8 w-8"
              :fill="n <= vRating ? 'currentColor' : 'none'"
              viewBox="0 0 24 24"
              stroke-width="1.2"
              stroke="currentColor"
            >
              <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
            </svg>
          </button>
        </div>

        <label class="field-label mt-6">Comment <span class="text-white/30">(optional)</span></label>
        <textarea v-model="vComment" rows="4" maxlength="1000" placeholder="Tell us about your visit" class="field"></textarea>

        <p v-if="vError" class="alert-error mt-4">{{ vError }}</p>

        <div class="mt-7 flex items-center justify-between gap-4">
          <button type="button" class="btn-text" @click="reviewing = null">Close</button>
          <button type="button" class="btn-gold" @click="submitReview">Submit</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Same dark room as the booking wizard — this is customer-facing, not staff. */
.customer-bookings {
  --accent: #c8a45d;
  font-family: var(--font-body);
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

.card,
.panel {
  background: #131110;
  border: 1px solid rgb(255 255 255 / 0.08);
}

/* The salon's name is the way back to its shopfront. */
.salon-link {
  text-decoration-line: underline;
  text-decoration-color: rgb(255 255 255 / 0.2);
  text-underline-offset: 6px;
  transition:
    color 0.3s ease,
    text-decoration-color 0.3s ease;
}

.salon-link:hover {
  color: var(--accent);
  text-decoration-color: var(--accent);
}

.card {
  transition: border-color 0.3s ease;
}

.card:hover {
  border-color: rgb(255 255 255 / 0.16);
}

/* Status tones */
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

.scrim {
  position: fixed;
  inset: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  background: rgb(8 7 6 / 0.85);
  backdrop-filter: blur(4px);
  overflow-y: auto;
}

.btn-gold,
.btn-ghost {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  padding: 0.8rem 1.6rem;
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
  border: 1px solid rgb(255 255 255 / 0.18);
  color: rgb(255 255 255 / 0.7);
}

.btn-ghost:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.btn-ghost-danger:hover {
  border-color: #f2a0a0;
  color: #f2a0a0;
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

.slot {
  border: 1px solid rgb(255 255 255 / 0.1);
  padding: 0.65rem 0.4rem;
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
  /* Makes the native date picker's own chrome dark too. */
  color-scheme: dark;
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

.empty {
  border: 1px solid rgb(255 255 255 / 0.08);
  background: #0e0d0c;
  padding: 2.5rem 1.5rem;
  text-align: center;
  font-size: 0.9rem;
  color: rgb(255 255 255 / 0.4);
}

.spinner {
  display: inline-block;
  height: 1.5rem;
  width: 1.5rem;
  border: 1px solid rgb(255 255 255 / 0.15);
  border-top-color: var(--accent);
  border-radius: 9999px;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
