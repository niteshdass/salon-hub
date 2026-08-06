import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import { useOnboardingStore } from './onboarding'

// Lets a test hold a request open to observe the in-flight `loading` state,
// then settle it by hand instead of asserting on an already-resolved promise.
function deferred() {
  let resolve
  let reject
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

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

  it('propagates a fetchStatus() failure and leaves the store reporting nothing done', async () => {
    // Direction matters: a store that fails "not done" is safe, one that fails
    // "done" would wave an owner past setup they never actually completed.
    const error = Object.assign(new Error('Forbidden'), {
      response: { status: 403, data: { message: 'This action is unauthorized.' } },
    })
    vi.mocked(api.get).mockRejectedValue(error)
    const store = useOnboardingStore()

    await expect(store.fetchStatus()).rejects.toBe(error)

    expect(store.steps).toEqual({ branch: false, services: false, staff: false, look: false })
    expect(store.requiredDone).toBe(false)
    expect(store.isComplete).toBe(false)
    expect(store.nextStep).toBe('branch')
  })

  it('toggles loading around a successful fetchStatus()', async () => {
    const { promise, resolve } = deferred()
    vi.mocked(api.get).mockReturnValue(promise)
    const store = useOnboardingStore()

    expect(store.loading).toBe(false)
    const call = store.fetchStatus()
    expect(store.loading).toBe(true)

    resolve(payload())
    await call

    expect(store.loading).toBe(false)
  })

  it('toggles loading back off after fetchStatus() rejects', async () => {
    const { promise, reject } = deferred()
    vi.mocked(api.get).mockReturnValue(promise)
    const store = useOnboardingStore()

    expect(store.loading).toBe(false)
    const call = store.fetchStatus()
    expect(store.loading).toBe(true)

    const error = new Error('Forbidden')
    reject(error)
    await expect(call).rejects.toBe(error)

    expect(store.loading).toBe(false)
  })

  it('lets a real fetchStatus() overwrite a locally-marked step, per the markStepDone docblock', async () => {
    vi.mocked(api.get).mockResolvedValue(payload())
    const store = useOnboardingStore()
    await store.fetchStatus()

    store.markStepDone('branch')
    expect(store.steps.branch).toBe(true)

    vi.mocked(api.get).mockResolvedValue(
      payload({ steps: { branch: false, services: false, staff: false, look: false } }),
    )
    await store.fetchStatus()

    expect(store.steps.branch).toBe(false)
  })
})
