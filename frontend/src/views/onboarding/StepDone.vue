<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import { parseApiError } from '@/lib/errors'
import { bookingUrl, downloadPoster } from '@/lib/qrPoster'

const emit = defineEmits(['finish', 'resume', 'leave'])

const authStore = useAuthStore()
const onboarding = useOnboardingStore()

const organization = computed(() => authStore.organization)
const salonName = computed(() => organization.value?.name ?? 'Your salon')
const url = computed(() => bookingUrl(organization.value))
const finishing = ref(false)

// markStepDone() only ever flips state locally, optimistically, without
// asking the server. This screen is the one place that tells the owner
// "you're live" — before it makes that claim it has to re-read the real
// status, or it can congratulate someone whose salon cannot take a booking.
const checking = ref(true)

// A failed re-fetch is not the same as the server saying "not ready" — it
// is the server never having answered at all. Falling back to whatever
// `onboarding.steps` already holds (optimistic local flips, or nothing)
// would either congratulate on unverified data or wrongly accuse the
// owner of missing steps that were never actually checked. This is its
// own state so neither the congrats branch nor the missing-steps branch
// can render while the read is unresolved.
const checkFailed = ref(false)

async function checkStatus() {
  checking.value = true
  checkFailed.value = false
  try {
    await onboarding.fetchStatus()
  } catch {
    checkFailed.value = true
  } finally {
    checking.value = false
  }
}

onMounted(checkStatus)

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

// 'idle' | 'copied' | 'unavailable'. `navigator.clipboard` is undefined on
// any non-secure context and on older mobile browsers — this is the
// payoff screen's primary call to action, so a silent throw here would
// leave the button looking like it did nothing on exactly the devices
// most likely to be used to set this up.
const copyState = ref('idle')

async function copy() {
  if (!navigator.clipboard?.writeText) {
    copyState.value = 'unavailable'
    return
  }
  try {
    await navigator.clipboard.writeText(url.value)
    copyState.value = 'copied'
    setTimeout(() => (copyState.value = 'idle'), 2000)
  } catch {
    copyState.value = 'unavailable'
  }
}

// 'idle' | 'downloading' | 'failed'. downloadPoster() was previously fired
// as a floating promise — if canvas rendering or the data-URL conversion
// throws, the owner clicked a button and nothing happened, with no way to
// tell whether it worked.
const posterState = ref('idle')

async function downloadPosterClicked() {
  posterState.value = 'downloading'
  try {
    await downloadPoster(url.value, salonName.value)
    posterState.value = 'idle'
  } catch {
    posterState.value = 'failed'
  }
}

// Every other action on this screen owns its failure; this one is the
// primary exit and must too. A rejected complete() used to flick the label
// back to "Go to dashboard" and say nothing, so the last button of the
// wizard looked broken. Same message-not-retry-system shape as
// SetupChecklistCard.dismiss(): the button stays clickable.
const finishError = ref('')

async function finish() {
  finishing.value = true
  finishError.value = ''
  try {
    await onboarding.complete()
    emit('finish')
  } catch (err) {
    finishError.value = parseApiError(err, "Couldn't save that — please try again.").message
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
  <div class="min-h-screen bg-paper px-4 py-12">
    <div v-if="checking" class="mx-auto max-w-xl text-center text-ink/60">Checking your setup…</div>

    <div v-else-if="checkFailed" class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-ink/10 text-2xl">?</div>
      <h1 class="mt-4 font-display text-3xl font-semibold text-ink">Couldn't check your setup</h1>
      <p class="mt-2 text-ink/60">
        We weren't able to reach the server to confirm {{ salonName }} is ready to take bookings.
      </p>
      <button
        type="button"
        class="sh-btn sh-btn-primary mt-6 w-full py-3"
        @click="checkStatus"
      >
        Try again
      </button>
    </div>

    <div v-else-if="!bookable" class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-amber-100 text-2xl">!</div>
      <h1 class="mt-4 font-display text-3xl font-semibold text-ink">Almost there</h1>
      <p class="mt-2 text-ink/60">
        {{ salonName }} isn't ready to take bookings yet.
      </p>

      <div class="sh-alert mt-4 border-amber-200 bg-amber-50 text-left text-amber-800">
        You still need to add {{ missing.join(' and ') }} before customers can book you.
      </div>

      <button
        type="button"
        class="sh-btn sh-btn-primary mt-6 w-full py-3"
        @click="resumeSetup"
      >
        Finish setup
      </button>

      <button
        type="button"
        class="mt-3 text-sm font-medium text-ink/60 transition hover:text-ink"
        @click="leaveWithoutCompleting"
      >
        I'll do this later
      </button>
    </div>

    <div v-else class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
      <h1 class="mt-4 font-display text-3xl font-semibold text-ink">
        {{ salonName }} is live
      </h1>
      <p class="mt-2 text-ink/60">Share this link and customers can book you right now.</p>

      <div class="sh-card mt-6 p-5">
        <p class="select-all break-all text-lg font-medium text-accent-700">{{ url }}</p>
        <button
          type="button"
          class="sh-btn sh-btn-primary mt-3 w-full py-3"
          @click="copy"
        >
          {{
            copyState === 'copied'
              ? 'Copied'
              : copyState === 'unavailable'
                ? "Can't copy automatically — select the link above"
                : 'Copy link'
          }}
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
          :disabled="posterState === 'downloading'"
          class="sh-btn mt-3 w-full py-2.5"
          @click="downloadPosterClicked"
        >
          {{
            posterState === 'downloading'
              ? 'Preparing your poster…'
              : posterState === 'failed'
                ? "Couldn't create the poster — try again"
                : 'Download QR poster for your shop'
          }}
        </button>

        <a :href="url" target="_blank" rel="noopener" class="mt-3 block text-sm font-semibold text-accent-600 hover:text-accent-700">
          Try booking yourself &rarr;
        </a>
      </div>

      <p v-if="finishError" class="mt-4 text-sm text-rose-600">{{ finishError }}</p>

      <button
        type="button"
        :disabled="finishing"
        class="mt-6 text-sm font-medium text-ink/60 transition hover:text-ink"
        @click="finish"
      >
        {{ finishing ? 'One moment…' : 'Go to dashboard' }}
      </button>
    </div>
  </div>
</template>
