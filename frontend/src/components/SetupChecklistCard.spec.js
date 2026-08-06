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

const push = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import SetupChecklistCard from './SetupChecklistCard.vue'

const statusWith = (steps, completedAt = null) => ({
  data: { data: { completed_at: completedAt, branch_id: 7, steps, next_step: 'done' } },
})

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Anwar', role },
    organization: { id: 9, name: 'Beauty Queen', slug: 'beautyqueen', onboarding_completed_at: null },
  })
}

function mountCard() {
  return mount(SetupChecklistCard)
}

describe('SetupChecklistCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    push.mockReset()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('shows the count of finished steps for an owner who has not finished setup', async () => {
    loginAs('owner')
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: false, look: false }),
    )

    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(true)
    expect(wrapper.text()).toContain('2 of 4 done')
    expect(wrapper.text()).toContain('Add who works there')
  })

  it('does not render for a manager, who has nothing to nag about here', async () => {
    loginAs('manager')

    const wrapper = mountCard()
    await flushPromises()

    // A manager should not even trigger the status fetch this card is
    // gated behind.
    expect(api.get).not.toHaveBeenCalled()
    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('does not render once onboarding is marked complete, even if a step still shows unfinished', async () => {
    loginAs('owner')
    // look intentionally left false: proves this is the completed_at gate,
    // not the doneCount gate, that is hiding the card.
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: false }, '2026-08-06T10:00:00+00:00'),
    )

    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('does not render once every step is done, even before completion is stamped', async () => {
    loginAs('owner')
    // completed_at intentionally left null: proves this is the doneCount
    // gate, not the completed_at gate, that is hiding the card.
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: true }, null),
    )

    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('stays silent when it could not read the status, rather than telling a finished salon it has done nothing', async () => {
    loginAs('owner')
    // A salon that is fully configured and already stamped complete. The
    // failed fetch used to leave `steps` at its all-false default, so this
    // card appeared on that owner's dashboard reading "0 of 4 done" — its
    // only reachable render was the one that was false.
    useAuthStore().organization.onboarding_completed_at = '2026-08-06T10:00:00+00:00'
    vi.mocked(api.get).mockRejectedValue(new Error('network down'))

    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.text()).not.toContain('0 of 4 done')
    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('stays silent when it could not read the status, even after a wizard screen optimistically flipped a step earlier in the session', async () => {
    loginAs('owner')
    // markStepDone() is deliberately local and optimistic. This card is the
    // one consumer that could turn that optimism into a claim about the
    // world, so a failed read must silence it here too — not fall back to
    // whatever the local flips left behind.
    useOnboardingStore().markStepDone('branch')
    vi.mocked(api.get).mockRejectedValue(new Error('network down'))

    const wrapper = mountCard()
    await flushPromises()

    // Ordered the other way round from the test above so that a regression
    // in the `loaded` gate is proven to fail on the structural assertion as
    // well as on the copy.
    expect(wrapper.find('section').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('1 of 4 done')
  })

  it('"Don\'t show this again" calls /onboarding/complete and the card then disappears', async () => {
    loginAs('owner')
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: false, staff: false, look: false }),
    )
    vi.mocked(api.post).mockResolvedValue(
      statusWith({ branch: true, services: false, staff: false, look: false }, '2026-08-06T10:00:00+00:00'),
    )

    const wrapper = mountCard()
    await flushPromises()
    expect(wrapper.find('section').exists()).toBe(true)

    const dismissButton = wrapper.findAll('button').find((b) => b.text().includes("Don't show this again"))
    await dismissButton.trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/onboarding/complete')
    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('shows a plain-language message and stays visible when dismissing fails, instead of going silent', async () => {
    loginAs('owner')
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: false, staff: false, look: false }),
    )
    vi.mocked(api.post).mockRejectedValue(new Error('network down'))

    const wrapper = mountCard()
    await flushPromises()

    const dismissButton = wrapper.findAll('button').find((b) => b.text().includes("Don't show this again"))
    await dismissButton.trigger('click')
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(true)
    expect(wrapper.text()).toContain("Couldn't save that")
    // The button must recover, not stay stuck showing the in-flight label.
    expect(wrapper.text()).toContain("Don't show this again")
  })
})
