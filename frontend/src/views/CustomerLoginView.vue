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
  <!--
    Signing in is the same dark room as the booking wizard: a customer arriving
    from a salon's site should not blink at a white page. No tenant is resolved
    here, so the accent is the house brass.
  -->
  <div class="customer-auth flex min-h-screen items-center justify-center px-6">
    <div class="panel w-full max-w-md p-8 sm:p-10">
      <p class="rule-label text-[var(--accent)]">My bookings</p>
      <h1 class="mt-5 font-display text-3xl leading-tight text-white">Sign in to your bookings</h1>
      <p class="mt-2 text-sm text-white/45">No password. We email you a code.</p>

      <p v-if="notice" class="alert-ok mt-6">{{ notice }}</p>
      <p v-if="error" class="alert-error mt-6">{{ error }}</p>

      <form v-if="step === 'email'" class="mt-8" @submit.prevent="sendCode">
        <label class="field-label">Email</label>
        <input v-model="email" type="email" required autocomplete="email" placeholder="you@example.com" class="field" />
        <button type="submit" :disabled="auth.loading" class="btn-gold mt-6 w-full">
          <span v-if="auth.loading" class="spinner spinner-sm" />
          {{ auth.loading ? 'Sending…' : 'Send code' }}
        </button>
      </form>

      <form v-else class="mt-8" @submit.prevent="submitCode">
        <label class="field-label">6-digit code</label>
        <input
          v-model="code"
          inputmode="numeric"
          maxlength="6"
          required
          autocomplete="one-time-code"
          placeholder="000000"
          class="field field-code"
        />
        <button type="submit" :disabled="auth.loading" class="btn-gold mt-6 w-full">
          <span v-if="auth.loading" class="spinner spinner-sm" />
          {{ auth.loading ? 'Verifying…' : 'Sign in' }}
        </button>
        <button type="button" class="btn-text mt-6 w-full text-center" @click="sendCode">Resend code</button>
      </form>

      <!--
        A way out, in both directions: back to the site they came from, and
        across to the staff door for anyone who arrived at the wrong one.
      -->
      <div class="mt-10 flex items-center justify-between gap-4 border-t border-white/8 pt-6">
        <RouterLink to="/" class="btn-text">← Back</RouterLink>
        <RouterLink to="/login" class="btn-text">Salon log in</RouterLink>
      </div>
    </div>
  </div>
</template>

<style scoped>
.customer-auth {
  --accent: #c8a45d;
  background: #080706;
  color: #fff;
  font-family: var(--font-body);
}

.font-display {
  font-family: var(--font-display);
  font-weight: 400;
}

.rule-label {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.rule-label::before {
  content: '';
  width: 1.75rem;
  height: 1px;
  background: currentColor;
  opacity: 0.7;
}

.panel {
  background: #131110;
  border: 1px solid rgb(255 255 255 / 0.08);
}

.btn-gold {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  background: var(--accent);
  color: #0a0908;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  padding: 0.95rem 1.9rem;
  transition:
    background-color 0.3s ease,
    opacity 0.3s ease;
}

.btn-gold:hover:not(:disabled) {
  background: #fff;
}

.btn-gold:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.btn-text {
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgb(255 255 255 / 0.45);
  transition: color 0.3s ease;
}

.btn-text:hover {
  color: #fff;
}

.field-label {
  display: block;
  margin-bottom: 0.6rem;
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgb(255 255 255 / 0.5);
}

.field {
  width: 100%;
  border: 1px solid rgb(255 255 255 / 0.12);
  background: #0a0908;
  padding: 0.9rem 1rem;
  color: #fff;
  outline: none;
  transition:
    border-color 0.25s ease,
    background-color 0.25s ease;
}

.field::placeholder {
  color: rgb(255 255 255 / 0.25);
}

.field:focus {
  border-color: var(--accent);
  background: #0e0d0c;
}

/* Chrome paints autofilled inputs pale blue; keep the room dark. */
.field:-webkit-autofill,
.field:-webkit-autofill:hover,
.field:-webkit-autofill:focus {
  -webkit-text-fill-color: #fff;
  -webkit-box-shadow: 0 0 0 60rem #0a0908 inset;
  caret-color: #fff;
}

/* A one-time code reads as digits, not prose. */
.field-code {
  text-align: center;
  font-size: 1.5rem;
  letter-spacing: 0.5em;
  text-indent: 0.5em;
  font-variant-numeric: tabular-nums;
}

.field-code::placeholder {
  letter-spacing: 0.5em;
}

.alert-error {
  border: 1px solid rgb(242 160 160 / 0.35);
  background: rgb(242 160 160 / 0.07);
  padding: 0.9rem 1.1rem;
  font-size: 0.9rem;
  color: #f2a0a0;
}

.alert-ok {
  border: 1px solid rgb(143 191 154 / 0.35);
  background: rgb(143 191 154 / 0.07);
  padding: 0.9rem 1.1rem;
  font-size: 0.9rem;
  color: #8fbf9a;
}

.spinner {
  display: inline-block;
  height: 1.5rem;
  width: 1.5rem;
  border: 1px solid rgb(255 255 255 / 0.15);
  border-top-color: var(--accent);
  border-radius: 9999px;
  animation: spin 0.8s linear infinite;
}

.spinner-sm {
  height: 0.9rem;
  width: 0.9rem;
  border-top-color: #0a0908;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
