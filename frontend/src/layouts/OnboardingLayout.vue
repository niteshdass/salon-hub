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
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-2xl items-center justify-between px-4 py-3">
        <button
          v-if="canGoBack"
          type="button"
          class="text-sm font-medium text-slate-500 transition hover:text-slate-900"
          @click="$emit('back')"
        >
          &larr; Back
        </button>
        <span v-else class="text-sm font-semibold text-slate-900">{{ salonName }}</span>

        <div class="flex items-center gap-1.5" aria-hidden="true">
          <span
            v-for="dot in dots"
            :key="dot"
            class="h-2 rounded-full transition-all"
            :class="dot === step ? 'w-6 bg-indigo-600' : dot < step ? 'w-2 bg-indigo-300' : 'w-2 bg-slate-200'"
          />
        </div>

        <button
          v-if="canSkip"
          type="button"
          class="text-sm font-medium text-slate-500 transition hover:text-slate-900"
          @click="$emit('skip')"
        >
          Skip for now
        </button>
        <span v-else class="w-20" />
      </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 pb-32 pt-8">
      <p class="text-sm font-medium text-indigo-600">Step {{ step }} of {{ total }}</p>
      <h1 class="mt-1 font-[Fraunces_Variable,serif] text-2xl font-semibold text-slate-900 sm:text-3xl">
        {{ title }}
      </h1>
      <p v-if="subtitle" class="mt-2 text-slate-600">{{ subtitle }}</p>

      <div class="mt-6">
        <slot />
      </div>
    </main>

    <!-- Sticky, because on a phone the primary action must never be below
         the fold of a form the owner is still filling in. -->
    <div class="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white/95 backdrop-blur">
      <div class="mx-auto max-w-2xl px-4 py-3">
        <slot name="action" />
      </div>
    </div>
  </div>
</template>
