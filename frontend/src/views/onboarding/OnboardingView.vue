<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useOnboardingStore, STEPS } from '@/stores/onboarding'
import StepBranch from './StepBranch.vue'
import StepServices from './StepServices.vue'

const router = useRouter()
const onboarding = useOnboardingStore()

// 0..3 map to STEPS; 4 is the success screen.
const index = ref(0)
const ready = ref(false)
const current = computed(() => (index.value < STEPS.length ? STEPS[index.value] : 'done'))

onMounted(async () => {
  try {
    await onboarding.fetchStatus()
    const next = onboarding.nextStep
    // Resume where they left off. 'done' means every step is already
    // satisfied, so go straight to the payoff screen.
    index.value = next === 'done' ? STEPS.length : STEPS.indexOf(next)
  } finally {
    ready.value = true
  }
})

function advance(stepKey) {
  onboarding.markStepDone(stepKey)
  index.value = Math.min(index.value + 1, STEPS.length)
}

function skip() {
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

async function finish() {
  await onboarding.complete()
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
    <template v-else>
      <p class="p-8 text-slate-500">Step: {{ current }}</p>
      <!-- Tasks 10-12 replace this with the real screens. -->
      <div class="flex gap-3 px-8">
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-white" @click="advance(current)">Next</button>
        <button class="rounded-lg px-4 py-2 text-slate-500" @click="skip">Skip</button>
        <button class="rounded-lg px-4 py-2 text-slate-500" @click="back">Back</button>
        <button class="rounded-lg px-4 py-2 text-slate-500" @click="finish">Finish</button>
      </div>
    </template>
  </template>
</template>
