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

onMounted(() => {
  if (authStore.isOwner) onboarding.fetchStatus().catch(() => {})
})

const items = computed(() =>
  Object.entries(LABELS).map(([key, label]) => ({ key, label, done: onboarding.steps[key] })),
)
const doneCount = computed(() => items.value.filter((item) => item.done).length)

// Owners only, and only while something is genuinely unfinished. A salon
// that has completed the wizard, or that has every step satisfied, is not
// nagged.
const show = computed(() => authStore.isOwner && !onboarding.isComplete && doneCount.value < items.value.length)

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
  <section v-if="show" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-indigo-200">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="font-semibold text-slate-900">Finish setting up your salon</h2>
        <p class="text-sm text-slate-500">{{ doneCount }} of {{ items.length }} done</p>
      </div>
      <button
        type="button"
        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700"
        @click="router.push('/onboarding')"
      >
        Continue setup
      </button>
    </div>

    <ul class="mt-4 space-y-2">
      <li v-for="item in items" :key="item.key" class="flex items-center gap-2 text-sm">
        <span
          class="grid h-5 w-5 shrink-0 place-items-center rounded-full text-xs"
          :class="item.done ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'"
        >
          {{ item.done ? '✓' : '' }}
        </span>
        <span :class="item.done ? 'text-slate-400 line-through' : 'text-slate-700'">{{ item.label }}</span>
      </li>
    </ul>

    <p v-if="dismissError" class="mt-3 text-sm text-rose-600">{{ dismissError }}</p>

    <button
      type="button"
      :disabled="dismissing"
      class="mt-4 text-sm text-slate-400 transition hover:text-slate-700"
      @click="dismiss"
    >
      {{ dismissing ? 'One moment…' : "Don't show this again" }}
    </button>
  </section>
</template>
