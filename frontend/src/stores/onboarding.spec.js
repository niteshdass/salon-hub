import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import { useOnboardingStore } from './onboarding'

const payload = (overrides = {}) => ({
  data: {
    data: {
      completed_at: null,
      branch_id: 7,
      steps: { branch: false, services: false, staff: false, look: false },
      next_step: 'branch',
      ...overrides,
    },
  },
})

describe('useOnboardingStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('reports nothing done before the status is fetched', () => {
    const store = useOnboardingStore()

    expect(store.nextStep).toBe('branch')
    expect(store.requiredDone).toBe(false)
    expect(store.isComplete).toBe(false)
  })

  it('exposes the fetched steps', async () => {
    vi.mocked(api.get).mockResolvedValue(
      payload({ steps: { branch: true, services: true, staff: false, look: false }, next_step: 'staff' }),
    )
    const store = useOnboardingStore()

    await store.fetchStatus()

    expect(api.get).toHaveBeenCalledWith('/onboarding/status')
    expect(store.steps.branch).toBe(true)
    expect(store.nextStep).toBe('staff')
    expect(store.branchId).toBe(7)
    expect(store.requiredDone).toBe(false)
  })

  it('treats a salon with branch, services and staff as ready even without the look step', async () => {
    vi.mocked(api.get).mockResolvedValue(
      payload({ steps: { branch: true, services: true, staff: true, look: false }, next_step: 'look' }),
    )
    const store = useOnboardingStore()

    await store.fetchStatus()

    expect(store.requiredDone).toBe(true)
    expect(store.isComplete).toBe(false)
  })

  it('marks a step done locally so the wizard advances without a round trip', async () => {
    vi.mocked(api.get).mockResolvedValue(payload())
    const store = useOnboardingStore()
    await store.fetchStatus()

    store.markStepDone('branch')

    expect(store.steps.branch).toBe(true)
    expect(store.nextStep).toBe('services')
  })

  it('records completion from the server response', async () => {
    vi.mocked(api.post).mockResolvedValue(payload({ completed_at: '2026-08-06T10:00:00+00:00' }))
    const store = useOnboardingStore()

    await store.complete()

    expect(api.post).toHaveBeenCalledWith('/onboarding/complete')
    expect(store.isComplete).toBe(true)
  })
})
