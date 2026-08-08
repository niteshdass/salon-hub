<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AuthLayout from '@/layouts/AuthLayout.vue'

const router = useRouter()
const authStore = useAuthStore()

const form = reactive({
  salon_name: '',
  email: '',
  password: '',
  password_confirmation: '',
  phone: '',
})

const errors = ref({})
const generalError = ref('')

async function onSubmit() {
  errors.value = {}
  generalError.value = ''

  const payload = {
    salon_name: form.salon_name,
    email: form.email,
    password: form.password,
    password_confirmation: form.password_confirmation,
  }
  if (form.phone) payload.phone = form.phone

  try {
    await authStore.register(payload)
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
  <AuthLayout title="Register your salon" subtitle="Free to start. Your booking page is live in minutes.">
    <div
      v-if="generalError"
      class="sh-alert mb-5 border-rose-200 bg-rose-50 text-rose-700"
    >
      {{ generalError }}
    </div>

    <form class="space-y-5" @submit.prevent="onSubmit">
      <div>
        <label for="salon_name" class="sh-label">Salon name</label>
        <input
          id="salon_name"
          v-model="form.salon_name"
          type="text"
          autocomplete="organization"
          required
          class="sh-input"
          placeholder="Glamour Studio"
        />
        <p v-if="errors.salon_name" class="sh-error">{{ errors.salon_name[0] }}</p>
      </div>

      <div>
        <label for="email" class="sh-label">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          class="sh-input"
          placeholder="you@example.com"
        />
        <p v-if="errors.email" class="sh-error">{{ errors.email[0] }}</p>
      </div>

      <div>
        <label for="phone" class="sh-label">
          Phone <span class="font-normal text-ink/40">(optional)</span>
        </label>
        <input
          id="phone"
          v-model="form.phone"
          type="tel"
          autocomplete="tel"
          class="sh-input"
          placeholder="+1 555 123 4567"
        />
        <p v-if="errors.phone" class="sh-error">{{ errors.phone[0] }}</p>
      </div>

      <div>
        <label for="password" class="sh-label">Password</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="new-password"
          required
          class="sh-input"
          placeholder="••••••••"
        />
        <p v-if="errors.password" class="sh-error">{{ errors.password[0] }}</p>
      </div>

      <div>
        <label for="password_confirmation" class="sh-label">Confirm password</label>
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

      <button type="submit" :disabled="authStore.loading" class="sh-btn sh-btn-primary w-full py-3 text-base">
        {{ authStore.loading ? 'Creating account…' : 'Create account' }}
      </button>
    </form>

    <template #footer>
      Already have an account?
      <RouterLink to="/login" class="font-semibold text-accent-600 hover:text-accent-700">Sign in</RouterLink>
    </template>
  </AuthLayout>
</template>
