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
import StepStaff from './StepStaff.vue'

// OnboardingLayout owns the header, the step dots, and the skip/back
// buttons — none of that is this component's job. Stub it down to its two
// slots so every assertion below targets StepStaff's own markup.
const OnboardingLayoutStub = {
  name: 'OnboardingLayout',
  template: '<div><slot /><slot name="action" /></div>',
}

const SERVICES = {
  data: {
    data: [
      { id: 1, name: 'Hair cut' },
      { id: 2, name: 'Beard trim' },
      { id: 3, name: 'Shave' },
    ],
  },
}

function mountStepStaff(props = {}) {
  return mount(StepStaff, {
    props: { branchId: null, ...props },
    global: { stubs: { OnboardingLayout: OnboardingLayoutStub } },
  })
}

const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)
const buttonIncluding = (wrapper, text) => wrapper.findAll('button').find((b) => b.text().includes(text))

describe('StepStaff', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().setSession({
      token: 'test-token',
      user: { id: 1, name: 'Anwar' },
      organization: { id: 9, name: 'Anwar Salon' },
    })
    vi.mocked(api.get).mockReset().mockResolvedValue(SERVICES)
    vi.mocked(api.post).mockReset().mockResolvedValue({ data: { data: { id: 1 } } })
  })

  it('the solo path creates exactly one staff person carrying every service, and emits done', async () => {
    const wrapper = mountStepStaff()
    await flushPromises()

    await buttonIncluding(wrapper, 'I work alone').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledTimes(1)
    const [url, body] = vi.mocked(api.post).mock.calls[0]
    expect(url).toBe('/staff')
    expect(body).toEqual({
      name: 'Anwar',
      phone: null,
      email: null,
      service_ids: [1, 2, 3],
      // No branch was fetched (branchId is null), so this falls back to
      // registration's own Mon-Sat default.
      working_days_json: [1, 2, 3, 4, 5, 6],
      working_hours_json: { start: '09:00', end: '18:00' },
    })

    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('blocks adding an eleventh person and names the free-plan limit inline, without posting anything', async () => {
    const wrapper = mountStepStaff()
    await flushPromises()

    await buttonIncluding(wrapper, 'I have a team').trigger('click')

    // "I have a team" starts with one person already in the list; eight more
    // clicks reach nine, one short of the free plan's ceiling of ten.
    for (let i = 0; i < 8; i += 1) {
      await buttonNamed(wrapper, '+ Add another person').trigger('click')
    }
    expect(wrapper.findAll('input[placeholder="Name"]')).toHaveLength(9)

    // The message must not be showing yet below the limit.
    expect(wrapper.text()).not.toContain('Your free plan covers 10 people.')

    // One more click reaches ten — the free plan's ceiling — which is
    // exactly the point an eleventh row would be refused, so the wizard
    // blocks adding any further person right here rather than waiting for
    // the server to say so.
    await buttonNamed(wrapper, '+ Add another person').trigger('click')
    expect(wrapper.findAll('input[placeholder="Name"]')).toHaveLength(10)
    expect(wrapper.text()).toContain('Your free plan covers 10 people. Upgrade later to add more.')
    expect(buttonNamed(wrapper, '+ Add another person').attributes('disabled')).toBeDefined()

    // Trying again changes nothing further — still ten, still blocked.
    await buttonNamed(wrapper, '+ Add another person').trigger('click')
    expect(wrapper.findAll('input[placeholder="Name"]')).toHaveLength(10)
    expect(wrapper.text()).toContain('Your free plan covers 10 people. Upgrade later to add more.')
    expect(buttonNamed(wrapper, '+ Add another person').attributes('disabled')).toBeDefined()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('sends the services ticked for one person without bleeding into another person\'s payload', async () => {
    const wrapper = mountStepStaff()
    await flushPromises()

    await buttonIncluding(wrapper, 'I have a team').trigger('click')

    // Fill in the first person and untick "Beard trim" for them only.
    await wrapper.findAll('input[placeholder="Name"]')[0].setValue('Ruma')
    await wrapper.findAll('button').find((b) => b.text() === 'Beard trim').trigger('click')

    // Add a second person, who keeps every service ticked by default.
    await buttonNamed(wrapper, '+ Add another person').trigger('click')
    await wrapper.findAll('input[placeholder="Name"]')[1].setValue('Shila')

    await buttonNamed(wrapper, 'Continue').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledTimes(2)
    const [, rumaBody] = vi.mocked(api.post).mock.calls[0]
    const [, shilaBody] = vi.mocked(api.post).mock.calls[1]

    expect(rumaBody.name).toBe('Ruma')
    expect(rumaBody.service_ids).toEqual([1, 3])

    expect(shilaBody.name).toBe('Shila')
    expect(shilaBody.service_ids).toEqual([1, 2, 3])
  })

  it('keeps Continue disabled and explains why when a person has no name yet', async () => {
    const wrapper = mountStepStaff()
    await flushPromises()

    await buttonIncluding(wrapper, 'I have a team').trigger('click')

    // The first person is pre-filled with the owner's own name, so blank it
    // out to reach the "missing name" state this test targets.
    await wrapper.findAll('input[placeholder="Name"]')[0].setValue('')

    const continueButton = buttonNamed(wrapper, 'Continue')
    expect(continueButton.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Add a name for each person before continuing.')

    // A native `disabled` attribute already suppresses a jsdom click
    // regardless of whether save()'s own guard is correct, so that alone
    // would not prove the guard works. Force the click through to exercise
    // `if (!canSave.value) return` inside save() itself.
    continueButton.element.removeAttribute('disabled')
    await continueButton.trigger('click')
    await flushPromises()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('refuses to open either path when the services fetch fails, and never posts an empty service list', async () => {
    vi.mocked(api.get).mockReset().mockRejectedValue(new Error('Network error'))

    const wrapper = mountStepStaff()
    await flushPromises()

    // The guard here is structural: both entry cards leave the DOM, so `mode`
    // can never be set and save() is unreachable. That is what these three
    // assertions pin. A trailing `expect(api.post).not.toHaveBeenCalled()`
    // used to sit below them and could not fail — nothing in this test ever
    // clicks, and the buttons that would have posted have just been asserted
    // absent.
    expect(buttonIncluding(wrapper, 'I work alone')).toBeUndefined()
    expect(buttonIncluding(wrapper, 'I have a team')).toBeUndefined()
    expect(wrapper.text()).toContain("We couldn't load your services")
  })

  it('explains a rejected save in plain language and returns the owner to the team form without emitting done', async () => {
    vi.mocked(api.post).mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          message: 'Please double-check that row.',
          errors: {},
        },
      },
    })

    const wrapper = mountStepStaff()
    await flushPromises()

    await buttonIncluding(wrapper, 'I have a team').trigger('click')
    await wrapper.findAll('input[placeholder="Name"]')[0].setValue('Ruma')

    await buttonNamed(wrapper, 'Continue').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Please double-check that row.')
    expect(wrapper.emitted('done')).toBeUndefined()

    // The `finally` in save() must hand control back to the owner — a
    // rejected save that leaves the button disabled or the form gone traps
    // them.
    const continueButton = buttonNamed(wrapper, 'Continue')
    expect(continueButton.attributes('disabled')).toBeUndefined()
    expect(continueButton.text()).toBe('Continue')
  })
})
