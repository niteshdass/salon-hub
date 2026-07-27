<script setup>
import { ref, onMounted } from 'vue'
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

function money(v) {
  return `$${Number(v).toFixed(2)}`
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
  <div>
    <p v-if="loading" class="text-sm text-slate-500">Loading…</p>
    <p v-else-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template v-else>
      <section>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Upcoming</h2>
        <p v-if="!upcoming.length" class="mt-2 text-sm text-slate-500">No upcoming bookings.</p>
        <ul class="mt-3 space-y-3">
          <li v-for="b in upcoming" :key="b.id" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="font-medium text-slate-900">{{ b.service }} <span class="text-slate-400">·</span> {{ b.salon.name }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ b.booking_date }} at {{ b.start_time }} · {{ b.staff }} · {{ b.branch }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ money(b.price) }} · paid {{ money(b.amount_paid) }} · due {{ money(b.balance_due) }}</p>
              </div>
              <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ b.status }}</span>
            </div>
            <div v-if="b.can_manage" class="mt-3 flex gap-2">
              <button class="rounded-lg bg-slate-100 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-200" @click="openReschedule(b)">Reschedule</button>
              <button class="rounded-lg bg-rose-50 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-100" @click="cancel(b)">Cancel</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Past</h2>
        <p v-if="!past.length" class="mt-2 text-sm text-slate-500">No past bookings.</p>
        <ul class="mt-3 space-y-3">
          <li v-for="b in past" :key="b.id" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="font-medium text-slate-900">{{ b.service }} <span class="text-slate-400">·</span> {{ b.salon.name }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ b.booking_date }} at {{ b.start_time }} · {{ b.staff }}</p>
              </div>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ b.status }}</span>
            </div>
            <div v-if="b.review" class="mt-2 text-sm text-amber-600">★ {{ b.review.rating }} <span class="text-slate-400">{{ b.review.comment }}</span></div>
            <button v-else-if="b.can_review" class="mt-3 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-700 hover:bg-amber-100" @click="openReview(b)">Leave review</button>
          </li>
        </ul>
      </section>
    </template>

    <!-- Reschedule modal -->
    <div v-if="rescheduling" class="fixed inset-0 z-10 flex items-center justify-center bg-black/30 px-4" @click.self="rescheduling = null">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
        <h3 class="text-base font-semibold text-slate-900">Reschedule</h3>
        <label class="mt-4 block text-sm font-medium text-slate-700">Date</label>
        <input v-model="rDate" type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" @change="loadSlots" />
        <p v-if="rError" class="mt-3 text-sm text-rose-700">{{ rError }}</p>
        <p v-if="rLoadingSlots" class="mt-3 text-sm text-slate-500">Loading slots…</p>
        <div v-else class="mt-3 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
          <button v-for="s in rSlots" :key="s" type="button"
            class="rounded-lg border px-2.5 py-1 text-sm"
            :class="rSlot === s ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-700'"
            @click="rSlot = s">{{ s }}</button>
          <p v-if="!rSlots.length" class="text-sm text-slate-500">No open slots.</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-lg px-3 py-1.5 text-sm text-slate-500" @click="rescheduling = null">Close</button>
          <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white disabled:opacity-50" :disabled="!rSlot" @click="submitReschedule">Confirm</button>
        </div>
      </div>
    </div>

    <!-- Review modal -->
    <div v-if="reviewing" class="fixed inset-0 z-10 flex items-center justify-center bg-black/30 px-4" @click.self="reviewing = null">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
        <h3 class="text-base font-semibold text-slate-900">Leave a review</h3>
        <label class="mt-4 block text-sm font-medium text-slate-700">Rating</label>
        <select v-model.number="vRating" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option v-for="n in 5" :key="n" :value="n">{{ n }} star{{ n > 1 ? 's' : '' }}</option>
        </select>
        <label class="mt-4 block text-sm font-medium text-slate-700">Comment</label>
        <textarea v-model="vComment" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        <p v-if="vError" class="mt-3 text-sm text-rose-700">{{ vError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-lg px-3 py-1.5 text-sm text-slate-500" @click="reviewing = null">Close</button>
          <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm text-white" @click="submitReview">Submit</button>
        </div>
      </div>
    </div>
  </div>
</template>
