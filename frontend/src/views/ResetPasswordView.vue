<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import AuthLayout from '@/layouts/AuthLayout.vue'

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
  <AuthLayout
    title="Choose a new password"
    :subtitle="linkIsUsable ? `Resetting the password for ${email}.` : ''"
  >
    <div
      v-if="!linkIsUsable"
      class="sh-alert border-amber-200 bg-amber-50 text-amber-800"
    >
      This reset link is incomplete. Request a fresh one from the
      <RouterLink to="/forgot-password" class="font-semibold underline">
        forgot password
      </RouterLink>
      page.
    </div>

    <div
      v-else-if="done"
      class="sh-alert border-emerald-200 bg-emerald-50 text-emerald-800"
    >
      Password updated. Taking you to the sign-in page…
    </div>

    <template v-else>
      <div
        v-if="generalError"
        class="sh-alert mb-5 border-rose-200 bg-rose-50 text-rose-700"
      >
        {{ generalError }}
      </div>

      <form class="space-y-5" @submit.prevent="onSubmit">
        <div>
          <label for="password" class="sh-label">New password</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            required
            minlength="8"
            class="sh-input"
            placeholder="••••••••"
          />
          <p v-if="errors.password" class="sh-error">{{ errors.password[0] }}</p>
          <p v-else class="mt-1.5 text-xs text-ink/50">At least 8 characters.</p>
        </div>

        <div>
          <label for="password_confirmation" class="sh-label">Confirm new password</label>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            class="sh-input"
            placeholder="••••••••"
          />
        </div>

        <!-- The token is single-use and tied to this address, so a stale
             link fails here rather than on the email field. -->
        <p v-if="errors.email" class="text-sm text-rose-600">
          {{ errors.email[0] }}
          <RouterLink to="/forgot-password" class="font-semibold underline">
            Request a new link
          </RouterLink>
        </p>

        <button type="submit" :disabled="saving" class="sh-btn sh-btn-primary w-full py-3 text-base">
          {{ saving ? 'Updating…' : 'Update password' }}
        </button>
      </form>
    </template>

    <template #footer>
      <RouterLink to="/login" class="font-semibold text-accent-600 hover:text-accent-700">Back to sign in</RouterLink>
    </template>
  </AuthLayout>
</template>
