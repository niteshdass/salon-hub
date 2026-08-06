<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useOnboardingStore, STEPS, REQUIRED_STEPS } from '@/stores/onboarding'
import StepBranch from './StepBranch.vue'
import StepServices from './StepServices.vue'
import StepStaff from './StepStaff.vue'
import StepLook from './StepLook.vue'
import StepDone from './StepDone.vue'

const router = useRouter()
const onboarding = useOnboardingStore()

// 0..3 map to STEPS; 4 is the success screen.
const index = ref(0)
const ready = ref(false)
const current = computed(() => (index.value < STEPS.length ? STEPS[index.value] : 'done'))

// Re-read status and reposition on whichever step it says is next. Used on
// mount, and again when StepDone finds the server disagrees with the
// optimistic local state and the owner asks to fix it — pushing to
// '/onboarding' from inside the component that route already renders would
// be a same-route no-op, so StepDone asks the host to re-run this instead.
async function resume() {
  ready.value = false
  try {
    await onboarding.fetchStatus()
    const next = onboarding.nextStep
    // 'done' means every step is already satisfied, so go straight to the
    // payoff screen.
    index.value = next === 'done' ? STEPS.length : STEPS.indexOf(next)
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
// up whatever is unfinished.
function leave() {
  router.push('/dashboard')
}
</script>

<template>
  <div v-if="!ready" class="grid min-h-screen place-items-center text-slate-500">Loading…</div>
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
