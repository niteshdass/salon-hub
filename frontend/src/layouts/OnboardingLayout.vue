<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  step: { type: Number, required: true },
  total: { type: Number, default: 4 },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  canSkip: { type: Boolean, default: true },
  canGoBack: { type: Boolean, default: true },
})

defineEmits(['back', 'skip'])

const authStore = useAuthStore()
const salonName = computed(() => authStore.organization?.name ?? 'your salon')
const dots = computed(() => Array.from({ length: props.total }, (_, i) => i + 1))
</script>

<template>
  <div class="min-h-screen bg-paper">
    <header class="border-b border-ink/10 bg-white">
      <div class="mx-auto flex max-w-2xl items-center justify-between px-4 py-3">
        <button
          v-if="canGoBack"
          type="button"
          class="text-sm font-medium text-ink/60 transition hover:text-ink"
          @click="$emit('back')"
        >
          &larr; Back
        </button>
        <span v-else class="text-sm font-semibold text-ink">{{ salonName }}</span>

        <div class="flex items-center gap-1.5" aria-hidden="true">
          <span
            v-for="dot in dots"
            :key="dot"
            class="h-2 rounded-full transition-all"
            :class="dot === step ? 'w-6 bg-accent-500' : dot < step ? 'w-2 bg-accent-300' : 'w-2 bg-ink/15'"
          />
        </div>

        <button
          v-if="canSkip"
          type="button"
          class="text-sm font-medium text-ink/60 transition hover:text-ink"
          @click="$emit('skip')"
        >
          Skip for now
        </button>
        <span v-else class="w-20" />
      </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 pb-32 pt-8">
      <p class="text-sm font-medium text-accent-600">Step {{ step }} of {{ total }}</p>
      <h1 class="mt-1 font-display text-2xl font-semibold text-ink sm:text-3xl">
        {{ title }}
      </h1>
      <p v-if="subtitle" class="mt-2 text-ink/60">{{ subtitle }}</p>

      <div class="mt-6">
        <slot />
      </div>
    </main>

    <!-- Sticky, because on a phone the primary action must never be below
         the fold of a form the owner is still filling in. -->
    <div class="fixed inset-x-0 bottom-0 border-t border-ink/10 bg-white/95 backdrop-blur">
      <div class="mx-auto max-w-2xl px-4 py-3">
        <slot name="action" />
      </div>
    </div>
  </div>
</template>
