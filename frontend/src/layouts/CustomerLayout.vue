<script setup>
import { useRouter, RouterView } from 'vue-router'
import { useCustomerAuthStore } from '@/stores/customerAuth'

const router = useRouter()
const auth = useCustomerAuthStore()

async function signOut() {
  await auth.logout()
  router.push('/account/login')
}
</script>

<template>
  <!--
    Customer-facing chrome, so it wears the same dark room as the booking
    wizard rather than the salon owner's dashboard.
  -->
  <div class="customer-shell">
    <header class="border-b border-white/8">
      <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-6">
        <span class="font-display text-2xl text-white">My bookings</span>
        <button v-if="auth.isAuthenticated" type="button" class="btn-text" @click="signOut">Log out</button>
      </div>
    </header>

    <main class="mx-auto max-w-4xl px-6 py-12">
      <RouterView />
    </main>

    <p class="label pb-10 text-center text-white/25">Powered by SalonHub</p>
  </div>
</template>

<style scoped>
.customer-shell {
  min-height: 100vh;
  background: #080706;
  color: #fff;
  font-family: var(--font-body);
}

.font-display {
  font-family: var(--font-display);
  font-weight: 400;
}

.label {
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
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
  color: #c8a45d;
}
</style>
