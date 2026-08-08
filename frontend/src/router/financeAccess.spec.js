import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// The real router and its real guard — no vue-router mock — so this proves
// the actual `meta.roles` gate on the /finance route, not a stand-in.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import router from './index'
import { useAuthStore } from '@/stores/auth'

function signInAs(role, { onboardingCompletedAt = '2026-08-06T10:00:00+00:00' } = {}) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, name: 'Beauty Queen', onboarding_completed_at: onboardingCompletedAt },
  })
}

describe('/finance route access, driven through the real router', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    await router.replace('/terms')
  })

  it('lets an owner reach the Finance page', async () => {
    signInAs('owner')

    await router.push('/finance')

    expect(router.currentRoute.value.path).toBe('/finance')
    expect(router.currentRoute.value.name).toBe('finance')
  })

  it('redirects a manager away from /finance, to the dashboard', async () => {
    signInAs('manager')

    await router.push('/finance')

    expect(router.currentRoute.value.path).toBe('/dashboard')
  })

  it('redirects a staff member away from /finance, to the dashboard', async () => {
    signInAs('staff')

    await router.push('/finance')

    expect(router.currentRoute.value.path).toBe('/dashboard')
  })
})
