import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

import api, { TOKEN_KEY } from '@/lib/api'
import { ACCENT_STORAGE_KEY } from '@/lib/theme'

// The 401 branch of the response interceptor is a plain rejected-handler
// function tucked inside axios's InterceptorManager — there is no public
// axios API to fire a request through the client and observe it, so the
// handler is invoked directly the way axios itself would call it.
describe('api response interceptor — 401', () => {
  let assign
  const originalLocation = window.location

  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem(TOKEN_KEY, 'stale-token')
    localStorage.setItem(ACCENT_STORAGE_KEY, '#0f766e')
    // jsdom's window.location.assign is read-only and throws "Not
    // implemented" on real navigation; replace the whole object so the
    // redirect the interceptor triggers is just a spy call.
    assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, pathname: '/', assign },
    })
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: originalLocation,
    })
  })

  it('drops the remembered accent alongside the token before redirecting, so the reloaded login page starts on the brand terracotta', async () => {
    const rejected = api.interceptors.response.handlers[0].rejected

    await expect(rejected({ response: { status: 401 } })).rejects.toBeTruthy()

    expect(localStorage.getItem(TOKEN_KEY)).toBeNull()
    // main.js repaints from this key before any store exists on the reload
    // that follows — leaving it behind would wear the previous tenant's
    // colour into the login screen instead of falling back to BRAND_ACCENT.
    expect(localStorage.getItem(ACCENT_STORAGE_KEY)).toBeNull()
    expect(assign).toHaveBeenCalledWith('/login')
  })
})
