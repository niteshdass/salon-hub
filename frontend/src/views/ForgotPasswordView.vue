<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import AuthLayout from '@/layouts/AuthLayout.vue'

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
  <AuthLayout
    title="Forgot your password?"
    subtitle="Enter your email and we'll send you a reset link."
  >
    <div
      v-if="sent"
      class="sh-alert border-emerald-200 bg-emerald-50 text-emerald-800"
    >
      If <span class="font-semibold">{{ email }}</span> belongs to an account, a reset link is on
      its way. The link expires in 60 minutes.
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
          <label for="email" class="sh-label">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
            required
            class="sh-input"
            placeholder="you@example.com"
          />
          <p v-if="errors.email" class="sh-error">{{ errors.email[0] }}</p>
        </div>

        <button type="submit" :disabled="sending" class="sh-btn sh-btn-primary w-full py-3 text-base">
          {{ sending ? 'Sending…' : 'Send reset link' }}
        </button>
      </form>
    </template>

    <template #footer>
      <RouterLink to="/login" class="font-semibold text-accent-600 hover:text-accent-700">Back to sign in</RouterLink>
    </template>
  </AuthLayout>
</template>
