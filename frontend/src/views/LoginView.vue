<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AuthLayout from '@/layouts/AuthLayout.vue'

const router = useRouter()
const authStore = useAuthStore()

const form = reactive({
  email: '',
  password: '',
})

const errors = ref({})
// Seeded from the store when a stored token was just refused with a reason —
// a suspended or inactive salon, an account no longer linked to one. The
// router bounces those here, and this banner is the only place the person
// ever learns why. Read-once, so it does not haunt later visits.
const generalError = ref(authStore.takeSessionMessage())

async function onSubmit() {
  errors.value = {}
  generalError.value = ''

  try {
    await authStore.login({ email: form.email, password: form.password })
    router.push('/dashboard')
  } catch (err) {
    if (err.response?.status === 422 && err.response.data?.errors) {
      errors.value = err.response.data.errors
    } else {
      generalError.value =
        err.response?.data?.message || 'Something went wrong. Please try again.'
    }
  }
}
</script>

<template>
  <AuthLayout title="Welcome back" subtitle="Sign in to your Glowhub account.">
    <div
      v-if="generalError"
      class="auth-alert mb-5 border-rose-200 bg-rose-50 text-rose-700"
    >
      {{ generalError }}
    </div>

    <form class="space-y-5" @submit.prevent="onSubmit">
      <div>
        <label for="email" class="auth-label">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          class="auth-input"
          placeholder="you@example.com"
        />
        <p v-if="errors.email" class="auth-error">{{ errors.email[0] }}</p>
      </div>

      <div>
        <div class="mb-1.5 flex items-baseline justify-between">
          <label for="password" class="auth-label mb-0">Password</label>
          <RouterLink to="/forgot-password" class="auth-link text-sm">Forgot?</RouterLink>
        </div>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="current-password"
          required
          class="auth-input"
          placeholder="••••••••"
        />
        <p v-if="errors.password" class="auth-error">{{ errors.password[0] }}</p>
      </div>

      <button type="submit" :disabled="authStore.loading" class="auth-button">
        {{ authStore.loading ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>

    <template #footer>
      Don't have an account?
      <RouterLink to="/register" class="auth-link">Register a salon</RouterLink>
      <!--
        This page is the salon's own staff door, and it is the one a customer
        finds first. Say so, and point them at theirs, rather than letting them
        fail against a password they were never given.
      -->
      <p class="mt-2">
        Booked at a salon?
        <RouterLink to="/account/login" class="auth-link">See your bookings</RouterLink>
      </p>
    </template>
  </AuthLayout>
</template>
