import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls; keep everything else (including TOKEN_KEY) real
// so the test asserts against the actual constant rather than a hardcoded
// string that would silently drift from '@/lib/api'.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: {
      post: vi.fn(),
      get: vi.fn(),
    },
  }
})

import api, { TOKEN_KEY } from '@/lib/api'
import { useAuthStore } from './auth'
import { useThemeStore } from '@/stores/theme'
import { BRAND_ACCENT } from '@/lib/theme'

describe('useAuthStore', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.mocked(api.post).mockReset()
    vi.mocked(api.get).mockReset()
  })

  it('is unauthenticated for a fresh store', () => {
    const store = useAuthStore()

    expect(store.isAuthenticated).toBe(false)
    expect(store.token).toBeNull()
    expect(store.user).toBeNull()
  })

  it('rehydrates the token from localStorage when the store is created', () => {
    localStorage.setItem(TOKEN_KEY, 'existing-token')

    const store = useAuthStore()

    expect(store.isAuthenticated).toBe(true)
    expect(store.token).toBe('existing-token')
  })

  it('setSession persists the token under TOKEN_KEY and flips isAuthenticated', () => {
    const store = useAuthStore()

    store.setSession({
      token: 'abc123',
      user: { id: 1, role: 'owner' },
      organization: { id: 9 },
    })

    expect(store.isAuthenticated).toBe(true)
    expect(localStorage.getItem(TOKEN_KEY)).toBe('abc123')
  })

  it('logout clears the store and localStorage even when the API call rejects', async () => {
    const store = useAuthStore()
    store.setSession({ token: 'abc123', user: { id: 1 }, organization: { id: 9 } })
    vi.mocked(api.post).mockRejectedValueOnce(new Error('network down'))

    await store.logout()

    expect(store.isAuthenticated).toBe(false)
    expect(store.token).toBeNull()
    expect(localStorage.getItem(TOKEN_KEY)).toBeNull()
  })

  // The session-layer half of the suspension fix. The backend refuses
  // /auth/me with 403 and a sentence naming the state; the router calls
  // endSession with it and bounces to /login, where LoginView reads it once.
  // Without the hand-off the user sees a bare redirect and no reason.
  it('endSession drops the token and keeps the reason for the sign-in page', () => {
    const store = useAuthStore()
    store.setSession({ token: 'abc123', user: { id: 1 }, organization: { id: 9 } })

    store.endSession('This salon account has been suspended. Please contact support.')

    expect(store.isAuthenticated).toBe(false)
    expect(localStorage.getItem(TOKEN_KEY)).toBeNull()
    expect(store.sessionMessage).toBe(
      'This salon account has been suspended. Please contact support.'
    )
  })

  it('takeSessionMessage returns the reason once and then forgets it', () => {
    const store = useAuthStore()
    store.endSession('This salon account is inactive. Please contact support.')

    expect(store.takeSessionMessage()).toBe(
      'This salon account is inactive. Please contact support.'
    )
    // A second visit to /login is not the redirect that produced the message.
    expect(store.takeSessionMessage()).toBe('')
  })

  it('signing in again clears a previous refusal message', () => {
    const store = useAuthStore()
    store.endSession('This salon account has been suspended. Please contact support.')

    store.setSession({ token: 'fresh', user: { id: 2 }, organization: { id: 3 } })

    expect(store.sessionMessage).toBe('')
  })

  it('logout calls the server to revoke the token', async () => {
    const store = useAuthStore()
    store.setSession({ token: 'abc123', user: { id: 1 }, organization: { id: 9 } })
    vi.mocked(api.post).mockResolvedValueOnce({ data: {} })

    await store.logout()

    // Without this call, the client forgets the token but the bearer token
    // stays valid on the backend until it expires — the server call is the
    // effect under test here, not a side detail.
    expect(api.post).toHaveBeenCalledWith('/auth/logout')
  })
})

describe('auth store — accent handover', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('adopts the salon accent when a session starts', () => {
    useAuthStore().setSession({
      token: 't',
      user: { id: 1, role: 'staff' },
      organization: { id: 9, theme_color: '#0f766e' },
    })

    expect(useThemeStore().accent).toBe('#0f766e')
  })

  it('drops back to the brand when the session ends', () => {
    const auth = useAuthStore()
    auth.setSession({
      token: 't',
      user: { id: 1, role: 'staff' },
      organization: { id: 9, theme_color: '#0f766e' },
    })

    auth.clearSession()

    expect(useThemeStore().accent).toBe(BRAND_ACCENT)
  })
})
