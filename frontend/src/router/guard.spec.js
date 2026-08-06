import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { needsOnboarding } from './index'

// Mock only the axios calls; keep everything else (including TOKEN_KEY) real
// so nothing here drifts from the actual '@/lib/api' module.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'

const owner = (completedAt) => ({
  isAuthenticated: true,
  role: 'owner',
  organization: { onboarding_completed_at: completedAt },
})

const dashboard = { name: 'dashboard', path: '/dashboard', meta: { requiresAuth: true } }

describe('needsOnboarding', () => {
  it('sends an owner who has never finished setup to the wizard', () => {
    expect(needsOnboarding(owner(null), dashboard)).toBe(true)
  })

  it('leaves an owner who has finished alone', () => {
    expect(needsOnboarding(owner('2026-08-06T10:00:00+00:00'), dashboard)).toBe(false)
  })

  it('never diverts a manager or a staff member', () => {
    expect(needsOnboarding({ ...owner(null), role: 'manager' }, dashboard)).toBe(false)
    expect(needsOnboarding({ ...owner(null), role: 'staff' }, dashboard)).toBe(false)
  })

  it('does not divert the wizard route itself, or it would loop', () => {
    expect(needsOnboarding(owner(null), { name: 'onboarding', path: '/onboarding', meta: { requiresAuth: true } }))
      .toBe(false)
  })

  it('leaves public routes alone', () => {
    expect(needsOnboarding(owner(null), { name: 'salon-site', path: '/salon/alpha', meta: {} })).toBe(false)
  })

  it('waits until the organization has loaded rather than guessing', () => {
    expect(needsOnboarding({ isAuthenticated: true, role: 'owner', organization: null }, dashboard)).toBe(false)
  })
})

describe('needsOnboarding after a real onboarding.complete()', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.post).mockReset()
  })

  it('lets the owner through to /dashboard once complete() stamps the auth store organization from the server response', async () => {
    // This is the exact seam the two stores share with the guard: the
    // guard reads authStore.organization.onboarding_completed_at, and
    // nothing but onboarding.complete() is supposed to write it. A mocked
    // vue-router (as in OnboardingView.spec.js / StepDone.spec.js) cannot
    // show this — it has to be exercised through real stores.
    const authStore = useAuthStore()
    authStore.setSession({
      token: 'test-token',
      user: { id: 1, name: 'Anwar', role: 'owner' },
      organization: { id: 9, name: 'Beauty Queen', onboarding_completed_at: null },
    })
    expect(needsOnboarding(authStore, dashboard)).toBe(true)

    vi.mocked(api.post).mockResolvedValue({
      data: {
        data: {
          completed_at: '2026-08-06T10:00:00+00:00',
          branch_id: 7,
          steps: { branch: true, services: true, staff: true, look: true },
          next_step: 'done',
        },
      },
    })

    const onboarding = useOnboardingStore()
    await onboarding.complete()

    expect(needsOnboarding(authStore, dashboard)).toBe(false)
  })
})
