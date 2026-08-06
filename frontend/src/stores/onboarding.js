import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import api from '@/lib/api'

// The wizard's screen order. `look` is last and optional: the public page
// renders with defaults, so nothing about it stops a salon taking bookings.
export const STEPS = ['branch', 'services', 'staff', 'look']
export const REQUIRED_STEPS = ['branch', 'services', 'staff']

const NOTHING_DONE = { branch: false, services: false, staff: false, look: false }

export const useOnboardingStore = defineStore('onboarding', () => {
  const status = ref(null)
  const loading = ref(false)

  const steps = computed(() => status.value?.steps ?? { ...NOTHING_DONE })
  const branchId = computed(() => status.value?.branch_id ?? null)
  const isComplete = computed(() => !!status.value?.completed_at)

  // Whether the salon can actually take a booking. Distinct from
  // isComplete, which only says the owner has stopped being asked.
  const requiredDone = computed(() => REQUIRED_STEPS.every((key) => steps.value[key]))

  const nextStep = computed(() => STEPS.find((key) => !steps.value[key]) ?? 'done')

  async function fetchStatus() {
    loading.value = true
    try {
      const { data } = await api.get('/onboarding/status')
      status.value = data.data
      return status.value
    } finally {
      loading.value = false
    }
  }

  /**
   * Flip a step locally after its screen saved successfully, so the wizard
   * advances immediately. The server is still the authority — the next
   * fetchStatus() overwrites this — but re-fetching between every screen
   * would put a spinner between the owner and their next question.
   */
  function markStepDone(key) {
    if (!status.value) {
      status.value = { completed_at: null, branch_id: null, steps: { ...NOTHING_DONE }, next_step: 'branch' }
    }
    status.value.steps = { ...status.value.steps, [key]: true }
  }

  async function complete() {
    const { data } = await api.post('/onboarding/complete')
    status.value = data.data
    return status.value
  }

  return {
    status,
    loading,
    steps,
    branchId,
    isComplete,
    requiredDone,
    nextStep,
    fetchStatus,
    markStepDone,
    complete,
  }
})
