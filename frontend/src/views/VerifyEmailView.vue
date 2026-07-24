<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'

const route = useRoute()
const authStore = useAuthStore()

// The API signed these; we hand them straight back so the signature —
// which covers the API path and query — still validates.
const { id, hash, expires, signature } = route.query
const linkIsUsable = Boolean(id && hash && expires && signature)

const state = ref(linkIsUsable ? 'checking' : 'malformed')
const message = ref('')

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
  <main class="flex min-h-screen items-center justify-center p-6">
    <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-xl ring-1 ring-slate-200">
      <div
        class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white"
      >
        S
      </div>

      <template v-if="state === 'checking'">
        <h1 class="text-2xl font-bold text-slate-900">Verifying your email…</h1>
        <p class="mt-1 text-sm text-slate-500">This only takes a moment.</p>
      </template>

      <template v-else-if="state === 'verified'">
        <div
          class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100"
        >
          <svg class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Email verified</h1>
        <p class="mt-1 text-sm text-slate-500">{{ message }}</p>
      </template>

      <template v-else-if="state === 'malformed'">
        <h1 class="text-2xl font-bold text-slate-900">Incomplete link</h1>
        <p class="mt-1 text-sm text-slate-500">
          Open the link straight from your inbox, or sign in and request a new one.
        </p>
      </template>

      <template v-else>
        <h1 class="text-2xl font-bold text-slate-900">Verification failed</h1>
        <p class="mt-1 text-sm text-slate-500">{{ message }}</p>
        <p class="mt-1 text-sm text-slate-500">
          Sign in and use the banner at the top to send yourself a fresh link.
        </p>
      </template>

      <RouterLink
        :to="authStore.isAuthenticated ? '/dashboard' : '/login'"
        class="mt-6 inline-block rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
      >
        {{ authStore.isAuthenticated ? 'Go to dashboard' : 'Go to sign in' }}
      </RouterLink>
    </div>
  </main>
</template>
