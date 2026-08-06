import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }
})

// A single shared push mock so both OnboardingView's own useRouter() call
// and (when it is not stubbed) StepDone's see the same instance.
const push = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

import api from '@/lib/api'
import OnboardingView from './OnboardingView.vue'
import StepDone from './StepDone.vue'

const statusWith = (steps, nextStep) => ({
  data: { data: { completed_at: null, branch_id: 7, steps, next_step: nextStep } },
})

// Stubs that can emit 'skip' on demand, unlike the brief's inert
// data-test-only stubs — the skip-routing tests below need a real click to
// exercise OnboardingView's skip() function.
const SkippableStub = {
  props: ['branchId'],
  emits: ['done', 'skip', 'back'],
  template: '<button type="button" data-test="skip-btn" @click="$emit(\'skip\')" />',
}

const mountView = (stubs = {}) =>
  mount(OnboardingView, {
    global: {
      stubs: {
        StepBranch: { template: '<div data-test="branch" />' },
        StepServices: { template: '<div data-test="services" />' },
        StepStaff: { template: '<div data-test="staff" />' },
        StepLook: { template: '<div data-test="look" />' },
        StepDone: { template: '<div data-test="done" />' },
        ...stubs,
      },
    },
  })

describe('OnboardingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    push.mockReset()
  })

  it('opens on the first step a fresh salon has not done', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: false, services: false, staff: false, look: false }, 'branch'),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-test="branch"]').exists()).toBe(true)
  })

  it('skips past the steps already finished', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: false, look: false }, 'staff'),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-test="branch"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="staff"]').exists()).toBe(true)
  })

  it('goes straight to the payoff screen when everything is already set up', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: true }, 'done'),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-test="done"]').exists()).toBe(true)
  })

  it('skipping a required step (branch) leaves for the dashboard instead of advancing to the next screen', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: false, services: false, staff: false, look: false }, 'branch'),
    )

    const wrapper = mountView({ StepBranch: SkippableStub })
    await flushPromises()

    await wrapper.find('[data-test="skip-btn"]').trigger('click')
    await flushPromises()

    expect(push).toHaveBeenCalledWith('/dashboard')
    // A required step is not something you can skip your way past — the
    // success screen must never appear as a result of this click.
    expect(wrapper.find('[data-test="done"]').exists()).toBe(false)
  })

  it('skipping the optional look step advances to the success screen instead of leaving', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: false }, 'look'),
    )

    const wrapper = mountView({ StepLook: SkippableStub })
    await flushPromises()

    await wrapper.find('[data-test="skip-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="done"]').exists()).toBe(true)
    // look never affects bookability, so skipping it must not be treated
    // like skipping a required step.
    expect(push).not.toHaveBeenCalledWith('/dashboard')
  })

  it('routes back to the step StepDone found still unsatisfied, using the real StepDone component', async () => {
    // The host originally thought everything was done (its own fetch said
    // so), landing on StepDone — but StepDone's own re-fetch (required by
    // the "never claim live on optimistic state" rule) finds staff is not
    // actually satisfied. Clicking "Finish setup" there must not be a
    // same-route router.push('/onboarding') no-op — it has to ask this
    // host to re-run its resume logic, which repositions on 'staff'.
    vi.mocked(api.get)
      .mockResolvedValueOnce(statusWith({ branch: true, services: true, staff: true, look: true }, 'done'))
      .mockResolvedValueOnce(statusWith({ branch: true, services: true, staff: false, look: true }, 'staff'))
      .mockResolvedValueOnce(statusWith({ branch: true, services: true, staff: false, look: true }, 'staff'))

    const wrapper = mountView({ StepDone, StepStaff: { template: '<div data-test="staff" />' } })
    await flushPromises()

    expect(wrapper.find('[data-test="staff"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Finish setup')

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="staff"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Finish setup')
  })

  it('leaves for the dashboard when StepDone (still not bookable) is dismissed with "I\'ll do this later"', async () => {
    // The host's own nextStep getter is derived from `steps`, so its first
    // fetch must show every step done to land directly on StepDone (the
    // only way this screen is really reached) — StepDone's own re-fetch is
    // what then discovers services is not actually satisfied.
    vi.mocked(api.get)
      .mockResolvedValueOnce(statusWith({ branch: true, services: true, staff: true, look: true }, 'done'))
      .mockResolvedValue(statusWith({ branch: true, services: false, staff: true, look: true }, 'services'))

    const wrapper = mountView({ StepDone })
    await flushPromises()

    const laterButton = wrapper.findAll('button').find((b) => b.text().includes("I'll do this later"))
    await laterButton.trigger('click')
    await flushPromises()

    expect(push).toHaveBeenCalledWith('/dashboard')
  })
})
