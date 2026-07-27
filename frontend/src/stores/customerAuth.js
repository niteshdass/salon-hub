import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import customerApi, { CUSTOMER_TOKEN_KEY } from '@/lib/customerApi'

export const useCustomerAuthStore = defineStore('customerAuth', () => {
  const token = ref(localStorage.getItem(CUSTOMER_TOKEN_KEY) || null)
  const account = ref(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value)

  function setToken(value) {
    token.value = value
    localStorage.setItem(CUSTOMER_TOKEN_KEY, value)
  }

  function clear() {
    token.value = null
    account.value = null
    localStorage.removeItem(CUSTOMER_TOKEN_KEY)
  }

  async function requestCode(email) {
    loading.value = true
    try {
      await customerApi.post('/customer/auth/request-code', { email })
    } finally {
      loading.value = false
    }
  }

  async function verifyCode(email, code) {
    loading.value = true
    try {
      const { data } = await customerApi.post('/customer/auth/verify-code', { email, code })
      setToken(data.token)
      account.value = data.account
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    const { data } = await customerApi.get('/customer/auth/me')
    account.value = data.account
    return data
  }

  async function logout() {
    try {
      await customerApi.post('/customer/auth/logout')
    } catch {
      // Clear regardless.
    } finally {
      clear()
    }
  }

  return { token, account, loading, isAuthenticated, requestCode, verifyCode, fetchMe, logout }
})
