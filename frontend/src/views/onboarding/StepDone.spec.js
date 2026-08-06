import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls; keep everything else (including TOKEN_KEY) real
// so nothing here drifts from the actual '@/lib/api' module.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn() },
  }
})

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import StepDone from './StepDone.vue'

const statusWith = (steps) => ({
  data: { data: { completed_at: null, branch_id: 7, steps, next_step: 'done' } },
})

function mountStepDone() {
  return mount(StepDone)
}

describe('StepDone', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().setSession({
      token: 'test-token',
      user: { id: 1, name: 'Anwar', role: 'owner' },
      organization: { id: 9, name: 'Beauty Queen', slug: 'beautyqueen', primary_domain: 'beautyqueen.salonhub.com' },
    })
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('re-reads status on mount and does not congratulate when the server reports a required step unsatisfied, showing what is missing instead', async () => {
    // Local optimistic state would have every step marked done (that is
    // the only way this screen is ever reached), but the server disagrees
    // about staff — this is exactly the case markStepDone's optimism can
    // produce and the one this screen has to catch before it speaks.
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: false, look: true }),
    )

    const wrapper = mountStepDone()
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/onboarding/status')
    expect(wrapper.text()).not.toContain('is live')
    expect(wrapper.text()).toContain('who works there')
    // The congratulatory share/QR call-to-action must not appear alongside
    // a "you're not ready" message.
    expect(wrapper.text()).not.toContain('Copy link')
  })

  it('offers a way back to fix what is missing, without silently marking onboarding complete', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: false, staff: true, look: true }),
    )

    const wrapper = mountStepDone()
    await flushPromises()

    // "Finish setup" is the first button rendered in the not-bookable
    // branch — asks the host to re-run its resume logic rather than
    // navigating to '/onboarding' directly, which would be a same-route
    // no-op since this component already lives inside that route.
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('resume')).toBeTruthy()
    // Fixing what's missing must not be conflated with completing —
    // /onboarding/complete is never called from the not-bookable branch.
    expect(api.post).not.toHaveBeenCalled()
  })

  it('"I\'ll do this later" leaves without stamping onboarding complete, unlike the bookable branch\'s "Go to dashboard"', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: false, staff: true, look: true }),
    )

    const wrapper = mountStepDone()
    await flushPromises()

    const laterButton = wrapper.findAll('button').find((b) => b.text().includes("I'll do this later"))
    await laterButton.trigger('click')
    await flushPromises()

    expect(wrapper.emitted('leave')).toBeTruthy()
    // Leaving from the not-bookable branch must not call complete() — doing
    // so would stop the router guard ever sending this owner back to finish
    // setup, leaving them with no nudge but the dashboard card.
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows the live booking link once the server confirms every required step is satisfied', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: true }),
    )

    const wrapper = mountStepDone()
    await flushPromises()

    expect(wrapper.text()).toContain('Beauty Queen is live')
    expect(wrapper.text()).toContain('https://beautyqueen.salonhub.com')
  })

  it('completing the payoff screen calls /onboarding/complete and then emits finish', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: true }),
    )
    vi.mocked(api.post).mockResolvedValue({
      data: { data: { completed_at: '2026-08-06T10:00:00+00:00', branch_id: 7, steps: {}, next_step: 'done' } },
    })

    const wrapper = mountStepDone()
    await flushPromises()

    const goButton = wrapper.findAll('button').find((b) => b.text().includes('Go to dashboard'))
    await goButton.trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/onboarding/complete')
    expect(wrapper.emitted('finish')).toBeTruthy()
  })
})
