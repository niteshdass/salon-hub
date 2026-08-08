<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import { parseApiError } from '@/lib/errors'

const router = useRouter()
const authStore = useAuthStore()
const onboarding = useOnboardingStore()

const LABELS = {
  branch: 'Add your address and opening hours',
  services: 'Add your services and prices',
  staff: 'Add who works there',
  look: 'Add your logo and salon story',
}

const dismissing = ref(false)
// A failed dismiss must say so — silently flipping `dismissing` back with
// no feedback is the exact shape two other screens on this branch were
// sent back for. This is a message, not a retry system: the button stays
// clickable and the owner can just try again.
const dismissError = ref('')

// "We haven't been told anything yet" is not "nothing has been done". A
// failed fetch leaves `steps` at its all-false default (or at whatever
// optimistic local flips a wizard screen left behind earlier in this SPA
// session), which used to render this card at "0 of 4 done" on the dashboard
// of a salon that is fully set up and already taking bookings. A card that
// does not know what it is talking about stays silent.
const loaded = ref(false)

onMounted(() => {
  if (!authStore.isOwner) return
  onboarding
    .fetchStatus()
    .then(() => {
      loaded.value = true
    })
    .catch(() => {})
})

const items = computed(() =>
  Object.entries(LABELS).map(([key, label]) => ({ key, label, done: onboarding.steps[key] })),
)
const doneCount = computed(() => items.value.filter((item) => item.done).length)

// Owners only, and only while something is genuinely unfinished. A salon
// that has completed the wizard, or that has every step satisfied, is not
// nagged.
const show = computed(
  () => authStore.isOwner && loaded.value && !onboarding.isComplete && doneCount.value < items.value.length,
)

async function dismiss() {
  dismissing.value = true
  dismissError.value = ''
  try {
    await onboarding.complete()
  } catch (err) {
    dismissError.value = parseApiError(err, "Couldn't save that — please try again.").message
  } finally {
    dismissing.value = false
  }
}
</script>

<template>
  <section v-if="show" class="sh-card border-accent-200 p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="font-display text-xl text-ink">Finish setting up your salon</h2>
        <p class="text-sm text-ink/60">{{ doneCount }} of {{ items.length }} done</p>
      </div>
      <button
        type="button"
        class="sh-btn sh-btn-primary"
        @click="router.push('/onboarding')"
      >
        Continue setup
      </button>
    </div>

    <ul class="mt-4 space-y-2">
      <li v-for="item in items" :key="item.key" class="flex items-center gap-2 text-sm">
        <span
          class="grid h-5 w-5 shrink-0 place-items-center rounded-full text-xs"
          :class="item.done ? 'bg-emerald-100 text-emerald-700' : 'bg-ink/5 text-ink/40'"
        >
          {{ item.done ? '✓' : '' }}
        </span>
        <span :class="item.done ? 'text-ink/40 line-through' : 'text-ink'">{{ item.label }}</span>
      </li>
    </ul>

    <p v-if="dismissError" class="sh-error mt-3">{{ dismissError }}</p>

    <button
      type="button"
      :disabled="dismissing"
      class="mt-4 text-sm text-ink/40 transition hover:text-ink"
      @click="dismiss"
    >
      {{ dismissing ? 'One moment…' : "Don't show this again" }}
    </button>
  </section>
</template>
