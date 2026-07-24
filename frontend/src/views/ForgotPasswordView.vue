<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'

const email = ref('')
const sending = ref(false)
const sent = ref(false)
const errors = ref({})
const generalError = ref('')

async function onSubmit() {
  errors.value = {}
  generalError.value = ''
  sending.value = true

  try {
    await api.post('/auth/forgot-password', { email: email.value })
    // The API answers the same for known and unknown addresses, so the
    // confirmation here is deliberately non-committal too.
    sent.value = true
  } catch (err) {
    const parsed = parseApiError(err)
    errors.value = parsed.errors
    if (!Object.keys(parsed.errors).length) generalError.value = parsed.message
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center p-6">
    <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl ring-1 ring-slate-200">
      <div class="mb-8 text-center">
        <div
          class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white"
        >
          S
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Forgot your password?</h1>
        <p class="mt-1 text-sm text-slate-500">
          Enter your email and we'll send you a reset link.
        </p>
      </div>

      <div
        v-if="sent"
        class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
      >
        If <span class="font-medium">{{ email }}</span> belongs to an account, a reset link is on
        its way. The link expires in 60 minutes.
      </div>

      <template v-else>
        <div
          v-if="generalError"
          class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
        >
          {{ generalError }}
        </div>

        <form class="space-y-4" @submit.prevent="onSubmit">
          <div>
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="email"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              placeholder="you@example.com"
            />
            <p v-if="errors.email" class="mt-1 text-sm text-rose-600">{{ errors.email[0] }}</p>
          </div>

          <button
            type="submit"
            :disabled="sending"
            class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ sending ? 'Sending…' : 'Send reset link' }}
          </button>
        </form>
      </template>

      <p class="mt-6 text-center text-sm text-slate-500">
        <RouterLink to="/login" class="font-medium text-indigo-600 hover:text-indigo-700">
          Back to sign in
        </RouterLink>
      </p>
    </div>
  </main>
</template>
