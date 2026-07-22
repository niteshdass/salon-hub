<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

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
  <main class="flex min-h-screen items-center justify-center p-6">
    <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl ring-1 ring-slate-200">
      <div class="mb-8 text-center">
        <div
          class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white"
        >
          S
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Create your salon</h1>
        <p class="mt-1 text-sm text-slate-500">Start managing your salon in minutes.</p>
      </div>

      <div
        v-if="generalError"
        class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
      >
        {{ generalError }}
      </div>

      <form class="space-y-4" @submit.prevent="onSubmit">
        <div>
          <label for="salon_name" class="mb-1 block text-sm font-medium text-slate-700">
            Salon name
          </label>
          <input
            id="salon_name"
            v-model="form.salon_name"
            type="text"
            autocomplete="organization"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="Glamour Studio"
          />
          <p v-if="errors.salon_name" class="mt-1 text-sm text-rose-600">
            {{ errors.salon_name[0] }}
          </p>
        </div>

        <div>
          <label for="email" class="mb-1 block text-sm font-medium text-slate-700">
            Email
          </label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="you@example.com"
          />
          <p v-if="errors.email" class="mt-1 text-sm text-rose-600">
            {{ errors.email[0] }}
          </p>
        </div>

        <div>
          <label for="phone" class="mb-1 block text-sm font-medium text-slate-700">
            Phone <span class="font-normal text-slate-400">(optional)</span>
          </label>
          <input
            id="phone"
            v-model="form.phone"
            type="tel"
            autocomplete="tel"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="+1 555 123 4567"
          />
          <p v-if="errors.phone" class="mt-1 text-sm text-rose-600">
            {{ errors.phone[0] }}
          </p>
        </div>

        <div>
          <label for="password" class="mb-1 block text-sm font-medium text-slate-700">
            Password
          </label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            placeholder="••••••••"
          />
          <p v-if="errors.password" class="mt-1 text-sm text-rose-600">
            {{ errors.password[0] }}
          </p>
        </div>

        <div>
          <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">
            Confirm password
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

        <button
          type="submit"
          :disabled="authStore.loading"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ authStore.loading ? 'Creating account…' : 'Create account' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account?
        <RouterLink to="/login" class="font-medium text-indigo-600 hover:text-indigo-700">
          Sign in
        </RouterLink>
      </p>
    </div>
  </main>
</template>
