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
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-4">
        <span class="text-lg font-semibold text-slate-900">My bookings</span>
        <button
          v-if="auth.isAuthenticated"
          class="text-sm text-slate-500 hover:text-slate-900"
          @click="signOut"
        >Log out</button>
      </div>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-8">
      <RouterView />
    </main>
  </div>
</template>
