<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore, STEPS, REQUIRED_STEPS } from '@/stores/onboarding'
import { deferOnboarding } from '@/lib/onboardingDeferral'
import StepBranch from './StepBranch.vue'
import StepServices from './StepServices.vue'
import StepStaff from './StepStaff.vue'
import StepLook from './StepLook.vue'
import StepDone from './StepDone.vue'

const router = useRouter()
const authStore = useAuthStore()
const onboarding = useOnboardingStore()

// 0..3 map to STEPS; 4 is the success screen.
const index = ref(0)
const ready = ref(false)
const current = computed(() => (index.value < STEPS.length ? STEPS[index.value] : 'done'))

// A failed status read is not "nothing is done yet". Without this, the wizard
// used to render step 1 anyway, with `branchId` null, and the only button on
// the screen refused every click in silence. Its own state, so no step screen
// can render on top of an answer the server never gave. Same shape as
// StepDone's `checkFailed`.
const loadFailed = ref(false)

// Re-read status and reposition on whichever step it says is next. Used on
// mount, and again when StepDone finds the server disagrees with the
// optimistic local state and the owner asks to fix it — pushing to
// '/onboarding' from inside the component that route already renders would
// be a same-route no-op, so StepDone asks the host to re-run this instead.
async function resume() {
  ready.value = false
  loadFailed.value = false
  try {
    await onboarding.fetchStatus()
    const next = onboarding.nextStep
    // 'done' means every step is already satisfied, so go straight to the
    // payoff screen.
    index.value = next === 'done' ? STEPS.length : STEPS.indexOf(next)
  } catch {
    loadFailed.value = true
  } finally {
    ready.value = true
  }
}

onMounted(resume)

function advance(stepKey) {
  onboarding.markStepDone(stepKey)
  index.value = Math.min(index.value + 1, STEPS.length)
}

// Skipping a required step (branch/services/staff) cannot be allowed to
// land on the success screen — that screen's whole job is to tell the
// owner they can take a booking, and a salon missing one of these
// literally cannot. Send them to the dashboard instead, where the
// unfinished-setup card picks it back up. `look` is the one step that
// never blocks bookability, so skipping it is free to advance normally.
function skip() {
  if (REQUIRED_STEPS.includes(current.value)) {
    leave()
    return
  }
  index.value = Math.min(index.value + 1, STEPS.length)
}

function back() {
  if (index.value === 0) {
    leave()
    return
  }
  index.value -= 1
}

// Leaving before the end is allowed by design — the dashboard card picks
// up whatever is unfinished. Nothing here stamps completion: the owner has
// finished nothing and must still be nudged. That is exactly why the
// deferral has to be recorded first — the router guard reads
// `onboarding_completed_at`, finds it still null, and would send them
// straight back into the wizard, which vue-router then aborts as a
// duplicated navigation, so the button appears to do nothing at all.
function leave() {
  deferOnboarding(authStore.organization?.id)
  router.push('/dashboard')
}
</script>

<template>
  <div v-if="!ready" class="grid min-h-screen place-items-center text-slate-500">Loading…</div>

  <div v-else-if="loadFailed" class="min-h-screen bg-slate-50 px-4 py-12">
    <div class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-200 text-2xl">?</div>
      <h1 class="mt-4 font-[Fraunces_Variable,serif] text-3xl font-semibold text-slate-900">
        We couldn't load your setup
      </h1>
      <p class="mt-2 text-slate-600">
        Something went wrong on our side, so we don't know how far you've got. Nothing you've
        already saved is lost.
      </p>
      <button
        type="button"
        class="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white transition hover:bg-indigo-700"
        @click="resume"
      >
        Try again
      </button>
      <button
        type="button"
        class="mt-3 text-sm font-medium text-slate-500 transition hover:text-slate-900"
        @click="leave"
      >
        I'll do this later
      </button>
    </div>
  </div>

  <template v-else>
    <StepBranch
      v-if="current === 'branch'"
      :branch-id="onboarding.branchId"
      @done="advance('branch')"
      @skip="skip"
      @back="back"
    />
    <StepServices v-else-if="current === 'services'" @done="advance('services')" @skip="skip" @back="back" />
    <StepStaff
      v-else-if="current === 'staff'"
      :branch-id="onboarding.branchId"
      @done="advance('staff')"
      @skip="skip"
      @back="back"
    />
    <StepLook v-else-if="current === 'look'" @done="advance('look')" @skip="skip" @back="back" />
    <StepDone v-else @finish="leave" @leave="leave" @resume="resume" />
  </template>
</template>
