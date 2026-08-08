<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import AuthLayout from '@/layouts/AuthLayout.vue'

const route = useRoute()
const authStore = useAuthStore()

// The API signed these; we hand them straight back so the signature —
// which covers the API path and query — still validates.
const { id, hash, expires, signature } = route.query
const linkIsUsable = Boolean(id && hash && expires && signature)

const state = ref(linkIsUsable ? 'checking' : 'malformed')
const message = ref('')

const heading = computed(
  () =>
    ({
      checking: 'Verifying your email…',
      verified: 'Email verified',
      malformed: 'Incomplete link',
      failed: 'Verification failed',
    })[state.value],
)

const subtitle = computed(
  () =>
    ({
      checking: 'This only takes a moment.',
      verified: message.value,
      malformed: 'Open the link straight from your inbox, or sign in and request a new one.',
      failed: message.value,
    })[state.value],
)

onMounted(async () => {
  if (!linkIsUsable) return

  try {
    const { data } = await api.get(`/auth/verify-email/${id}/${hash}`, {
      params: { expires, signature },
    })
    message.value = data.message
    state.value = 'verified'

    // Refresh the cached user so the dashboard banner disappears.
    if (authStore.isAuthenticated) {
      await authStore.fetchMe().catch(() => {})
    }
  } catch (err) {
    message.value = parseApiError(err, 'This verification link is invalid or has expired.').message
    state.value = 'failed'
  }
})
</script>

<template>
  <AuthLayout :title="heading" :subtitle="subtitle">
    <div class="text-center">
      <div
        v-if="state === 'verified'"
        class="mx-auto mb-5 grid h-12 w-12 place-items-center rounded-full bg-emerald-50 text-emerald-600"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>

      <p v-if="state === 'failed'" class="mb-5 text-sm text-ink/60">
        Sign in and use the banner at the top to send yourself a fresh link.
      </p>

      <RouterLink
        :to="authStore.isAuthenticated ? '/dashboard' : '/login'"
        class="sh-btn sh-btn-primary py-3 text-base"
      >
        {{ authStore.isAuthenticated ? 'Go to dashboard' : 'Go to sign in' }}
      </RouterLink>
    </div>
  </AuthLayout>
</template>
