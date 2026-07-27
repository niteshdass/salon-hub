<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCustomerAuthStore } from '@/stores/customerAuth'

const router = useRouter()
const auth = useCustomerAuthStore()

const step = ref('email') // 'email' | 'code'
const email = ref('')
const code = ref('')
const error = ref('')
const notice = ref('')

async function sendCode() {
  error.value = ''
  notice.value = ''
  try {
    await auth.requestCode(email.value)
    step.value = 'code'
    notice.value = 'Check your email for the 6-digit code.'
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not send the code. Try again.'
  }
}

async function submitCode() {
  error.value = ''
  try {
    await auth.verifyCode(email.value, code.value)
    router.push('/account')
  } catch (e) {
    error.value = e.response?.data?.message || 'Invalid or expired code.'
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
      <h1 class="text-lg font-semibold text-slate-900">Sign in to your bookings</h1>
      <p class="mt-1 text-sm text-slate-500">No password. We email you a code.</p>

      <p v-if="notice" class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ notice }}</p>
      <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

      <form v-if="step === 'email'" class="mt-5 space-y-4" @submit.prevent="sendCode">
        <div>
          <label class="block text-sm font-medium text-slate-700">Email</label>
          <input v-model="email" type="email" required autocomplete="email"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
        </div>
        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {{ auth.loading ? 'Sending…' : 'Send code' }}
        </button>
      </form>

      <form v-else class="mt-5 space-y-4" @submit.prevent="submitCode">
        <div>
          <label class="block text-sm font-medium text-slate-700">6-digit code</label>
          <input v-model="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-lg tracking-widest focus:border-indigo-500 focus:outline-none" />
        </div>
        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {{ auth.loading ? 'Verifying…' : 'Sign in' }}
        </button>
        <button type="button" class="w-full text-center text-sm text-slate-500 hover:text-slate-900" @click="sendCode">
          Resend code
        </button>
      </form>
    </div>
  </div>
</template>
