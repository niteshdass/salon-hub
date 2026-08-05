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
})
