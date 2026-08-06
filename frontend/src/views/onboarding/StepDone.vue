<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import { bookingUrl, downloadPoster } from '@/lib/qrPoster'

const emit = defineEmits(['finish', 'resume', 'leave'])

const authStore = useAuthStore()
const onboarding = useOnboardingStore()

const organization = computed(() => authStore.organization)
const salonName = computed(() => organization.value?.name ?? 'Your salon')
const url = computed(() => bookingUrl(organization.value))
const copied = ref(false)
const finishing = ref(false)

// markStepDone() only ever flips state locally, optimistically, without
// asking the server. This screen is the one place that tells the owner
// "you're live" — before it makes that claim it has to re-read the real
// status, or it can congratulate someone whose salon cannot take a booking.
const checking = ref(true)

onMounted(async () => {
  try {
    await onboarding.fetchStatus()
  } finally {
    checking.value = false
  }
})

// requiredDone is derived from the server's answer (branch/services/staff),
// never from the optimistic local flips — this is the gate for whether we
// congratulate or confess something is still missing.
const bookable = computed(() => onboarding.requiredDone)

const missing = computed(() =>
  [
    !onboarding.steps.branch && 'your address',
    !onboarding.steps.services && 'your services',
    !onboarding.steps.staff && 'who works there',
  ].filter(Boolean),
)

const shareText = computed(() => `Book an appointment at ${salonName.value}: ${url.value}`)
const whatsapp = computed(() => `https://wa.me/?text=${encodeURIComponent(shareText.value)}`)
const facebook = computed(() => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url.value)}`)

async function copy() {
  await navigator.clipboard.writeText(url.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

async function finish() {
  finishing.value = true
  try {
    await onboarding.complete()
    emit('finish')
  } finally {
    finishing.value = false
  }
}

// Go fix what's missing. This component already lives inside the
// '/onboarding' route, so pushing back to that same path would be a
// same-route no-op — ask the host to re-run its own resume logic instead,
// which re-fetches status and repositions on the first unsatisfied step.
function resumeSetup() {
  emit('resume')
}

// Leaving is allowed by design everywhere else in the wizard (see
// OnboardingView's leave()) — but unlike finish(), this must not call
// onboarding.complete(). Stamping completion for a salon that is not
// actually bookable would stop the router guard ever sending them back to
// finish setup, and they would have only the dashboard card to notice.
function leaveWithoutCompleting() {
  emit('leave')
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 px-4 py-12">
    <div v-if="checking" class="mx-auto max-w-xl text-center text-slate-500">Checking your setup…</div>

    <div v-else-if="!bookable" class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-amber-100 text-2xl">!</div>
      <h1 class="mt-4 font-[Fraunces_Variable,serif] text-3xl font-semibold text-slate-900">Almost there</h1>
      <p class="mt-2 text-slate-600">
        {{ salonName }} isn't ready to take bookings yet.
      </p>

      <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-left text-sm text-amber-800">
        You still need to add {{ missing.join(' and ') }} before customers can book you.
      </div>

      <button
        type="button"
        class="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white transition hover:bg-indigo-700"
        @click="resumeSetup"
      >
        Finish setup
      </button>

      <button
        type="button"
        class="mt-3 text-sm font-medium text-slate-500 transition hover:text-slate-900"
        @click="leaveWithoutCompleting"
      >
        I'll do this later
      </button>
    </div>

    <div v-else class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
      <h1 class="mt-4 font-[Fraunces_Variable,serif] text-3xl font-semibold text-slate-900">
        {{ salonName }} is live
      </h1>
      <p class="mt-2 text-slate-600">Share this link and customers can book you right now.</p>

      <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="break-all text-lg font-medium text-indigo-700">{{ url }}</p>
        <button
          type="button"
          class="mt-3 w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white transition hover:bg-indigo-700"
          @click="copy"
        >
          {{ copied ? 'Copied' : 'Copy link' }}
        </button>

        <div class="mt-3 grid grid-cols-2 gap-3">
          <a :href="whatsapp" target="_blank" rel="noopener" class="rounded-xl bg-emerald-600 px-4 py-2.5 font-medium text-white transition hover:bg-emerald-700">
            WhatsApp
          </a>
          <a :href="facebook" target="_blank" rel="noopener" class="rounded-xl bg-blue-600 px-4 py-2.5 font-medium text-white transition hover:bg-blue-700">
            Facebook
          </a>
        </div>

        <button
          type="button"
          class="mt-3 w-full rounded-xl px-4 py-2.5 font-medium text-slate-700 ring-1 ring-slate-300 transition hover:bg-slate-50"
          @click="downloadPoster(url, salonName)"
        >
          Download QR poster for your shop
        </button>

        <a :href="url" target="_blank" rel="noopener" class="mt-3 block text-sm font-medium text-indigo-600">
          Try booking yourself &rarr;
        </a>
      </div>

      <button
        type="button"
        :disabled="finishing"
        class="mt-6 text-sm font-medium text-slate-500 transition hover:text-slate-900"
        @click="finish"
      >
        {{ finishing ? 'One moment…' : 'Go to dashboard' }}
      </button>
    </div>
  </div>
</template>
