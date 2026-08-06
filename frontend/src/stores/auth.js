import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import api, { TOKEN_KEY } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem(TOKEN_KEY) || null)
  const user = ref(null)
  const organization = ref(null)
  const loading = ref(false)
  // Why the last session ended, when the reason is worth telling the user —
  // a suspended or inactive salon, or an account no longer linked to one.
  // The API answers /auth/me with that sentence; without somewhere to put it
  // the redirect to /login would be the only thing they ever saw.
  const sessionMessage = ref('')

  const isAuthenticated = computed(() => !!token.value)

  // Mirrors the backend policies (app/Policies): the owner configures the
  // organization, owner + manager run daily operations, staff only work
  // their own schedule. Hiding controls here is UX — the API is the gate.
  const role = computed(() => user.value?.role ?? null)
  const isOwner = computed(() => role.value === 'owner')
  const isStaff = computed(() => role.value === 'staff')
  const canManageOperations = computed(() => role.value === 'owner' || role.value === 'manager')

  // Unverified users are not locked out — the dashboard nags instead.
  const emailVerified = computed(() => user.value?.email_verified === true)

  function setSession(data) {
    token.value = data.token
    user.value = data.user
    organization.value = data.organization
    sessionMessage.value = ''
    localStorage.setItem(TOKEN_KEY, data.token)
  }

  function clearSession() {
    token.value = null
    user.value = null
    organization.value = null
    localStorage.removeItem(TOKEN_KEY)
  }

  // Drop the session AND record why, for the sign-in page to show. Used when
  // the server refuses a stored token for a reason the user can act on
  // (contacting support) rather than a plain expiry.
  function endSession(message) {
    clearSession()
    sessionMessage.value = message || ''
  }

  // Read-once: the message belongs to the redirect that produced it, not to
  // every later visit to /login.
  function takeSessionMessage() {
    const message = sessionMessage.value
    sessionMessage.value = ''
    return message
  }

  async function register(payload) {
    loading.value = true
    try {
      const { data } = await api.post('/auth/register', payload)
      setSession(data)
      return data
    } finally {
      loading.value = false
    }
  }

  async function login(payload) {
    loading.value = true
    try {
      const { data } = await api.post('/auth/login', payload)
      setSession(data)
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    const { data } = await api.get('/auth/me')
    user.value = data.user
    organization.value = data.organization
    return data
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      // Ignore network/auth errors — we clear the session regardless.
    } finally {
      clearSession()
    }
  }

  return {
    token,
    user,
    organization,
    loading,
    sessionMessage,
    isAuthenticated,
    role,
    isOwner,
    isStaff,
    canManageOperations,
    emailVerified,
    setSession,
    endSession,
    takeSessionMessage,
    register,
    login,
    fetchMe,
    logout,
  }
})
