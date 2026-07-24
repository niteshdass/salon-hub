<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'

const route = useRoute()
const router = useRouter()

// Both arrive in the emailed link; without them there is nothing to do.
const token = String(route.query.token || '')
const email = String(route.query.email || '')
const linkIsUsable = Boolean(token && email)

const form = reactive({ password: '', password_confirmation: '' })
const saving = ref(false)
const done = ref(false)
const errors = ref({})
const generalError = ref('')

async function onSubmit() {
  errors.value = {}
  generalError.value = ''
  saving.value = true

  try {
    await api.post('/auth/reset-password', {
      token,
      email,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })
    done.value = true
    // The reset revoked every token, so signing in again is mandatory.
    setTimeout(() => router.push('/login'), 2000)
  } catch (err) {
    const parsed = parseApiError(err)
    errors.value = parsed.errors
    if (!Object.keys(parsed.errors).length) generalError.value = parsed.message
  } finally {
    saving.value = false
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
        <h1 class="text-2xl font-bold text-slate-900">Choose a new password</h1>
        <p v-if="linkIsUsable" class="mt-1 text-sm text-slate-500">
          Resetting the password for {{ email }}.
        </p>
      </div>

      <div
        v-if="!linkIsUsable"
        class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
      >
        This reset link is incomplete. Request a fresh one from the
        <RouterLink to="/forgot-password" class="font-medium underline">
          forgot password
        </RouterLink>
        page.
      </div>

      <div
        v-else-if="done"
        class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
      >
        Password updated. Taking you to the sign-in page…
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
            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">
              New password
            </label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
              class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              placeholder="••••••••"
            />
            <p v-if="errors.password" class="mt-1 text-sm text-rose-600">{{ errors.password[0] }}</p>
            <p v-else class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
          </div>

          <div>
            <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">
              Confirm new password
            </label>
            <input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              placeholder="••••••••"
            />
          </div>

          <!-- The token is single-use and tied to this address, so a stale
               link fails here rather than on the email field. -->
          <p v-if="errors.email" class="text-sm text-rose-600">
            {{ errors.email[0] }}
            <RouterLink to="/forgot-password" class="font-medium underline">
              Request a new link
            </RouterLink>
          </p>

          <button
            type="submit"
            :disabled="saving"
            class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ saving ? 'Updating…' : 'Update password' }}
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
