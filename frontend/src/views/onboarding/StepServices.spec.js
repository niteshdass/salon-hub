import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import StepServices from './StepServices.vue'

const PRESETS = {
  data: {
    data: [
      {
        key: 'barber',
        label: 'Barber',
        services: [
          { name: 'Hair cut', duration: 30 },
          { name: 'Beard trim', duration: 15 },
        ],
      },
    ],
  },
}

const mountStep = () =>
  mount(StepServices, {
    global: { stubs: { OnboardingLayout: { template: '<div><slot /><slot name="action" /></div>' } } },
  })

describe('StepServices', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue(PRESETS)
    vi.mocked(api.post).mockReset().mockResolvedValue({ data: { data: [] } })
  })

  it('keeps Continue disabled while a ticked row has no price', async () => {
    const wrapper = mountStep()
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const continueButton = () => wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton().attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Add a price for every service you ticked')
  })

  it('enables Continue once every ticked row is priced', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    await priceInputs[0].setValue('12')
    await priceInputs[1].setValue('5')

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeUndefined()
  })

  it('ignores the price of a row the owner unticked', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    await wrapper.findAll('input[type="checkbox"]')[1].setValue(false)
    await wrapper.findAll('input[placeholder="Price"]')[0].setValue('12')

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeUndefined()
  })

  // Regression test for round-1 Critical 1/2: the first version of this test
  // ticked every row, so posted-index and full-list-index were identical by
  // construction and the test could not have caught a broken mapping. This
  // fixture unticks a row BEFORE the row the server blames, so the two
  // indices genuinely diverge: posted = [Hair cut, Shave, Wax] (indices
  // 0,1,2) while the full list is [Hair cut, Beard trim(unticked), Shave,
  // Wax] (indices 0,1,2,3). The server blames posted index 1 (Shave), which
  // sits at full-list index 2 — proving the fix translates through
  // postedRowIndexes rather than reusing the posted position directly.
  it('highlights only the row a 422 blames, translating the posted index back through the full row list', async () => {
    vi.mocked(api.get).mockReset().mockResolvedValue({
      data: {
        data: [
          {
            key: 'barber',
            label: 'Barber',
            services: [
              { name: 'Hair cut', duration: 30 },
              { name: 'Beard trim', duration: 15 },
              { name: 'Shave', duration: 20 },
              { name: 'Wax', duration: 10 },
            ],
          },
        ],
      },
    })
    // Every ticked row below carries a value the client itself accepts —
    // this 422 stands in for a rule the client does not mirror (e.g. the
    // server's `max:255` on name, or a race with another request) so the
    // rejection reaches the catch block instead of being blocked earlier by
    // canSave. That isolates the index-mapping bug this test targets.
    vi.mocked(api.post).mockReset().mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'Please fix the highlighted services.',
          errors: { 'rows.1.price': ['Price must be at least 0.'] },
        },
      },
    })

    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    // Untick Beard trim (full-list index 1) so it drops out of the posted
    // array entirely, shifting Shave and Wax's posted positions down by one.
    await wrapper.findAll('input[type="checkbox"]')[1].setValue(false)

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    // With Beard trim unticked there are 3 price inputs left, in full-list
    // order: Hair cut, Shave, Wax. All valid, so nothing here trips canSave.
    await priceInputs[0].setValue('12')
    await priceInputs[1].setValue('9')
    await priceInputs[2].setValue('8')

    await wrapper.findAll('button').find((b) => b.text().includes('Continue')).trigger('click')
    await flushPromises()

    const rows = wrapper.findAll('li')
    // rows[1] is Beard trim: unticked, never posted, must never be blamed.
    // rows[2] is Shave: posted index 1, the row the server actually named.
    expect(rows[0].text()).not.toContain('Price must be at least 0.')
    expect(rows[1].text()).not.toContain('Price must be at least 0.')
    expect(rows[2].text()).toContain('Price must be at least 0.')
    expect(rows[3].text()).not.toContain('Price must be at least 0.')

    expect(rows[0].classes()).not.toContain('ring-rose-300')
    expect(rows[1].classes()).not.toContain('ring-rose-300')
    expect(rows[2].classes()).toContain('ring-rose-300')
    expect(rows[3].classes()).not.toContain('ring-rose-300')
  })

  it('blocks Continue and names the problem when a ticked row has a negative price', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    await priceInputs[0].setValue('-1')
    await priceInputs[1].setValue('5')

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Enter a price of 0 or more for every service you ticked.')
    // The message must read as plain English, never a server field path
    // like "rows.0.price".
    expect(wrapper.text()).not.toContain('rows.')
  })

  it('blocks Continue and names the problem when a ticked row is missing its duration', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    await priceInputs[0].setValue('12')
    await priceInputs[1].setValue('5')

    // Blank the first row's duration — the field a non-technical owner
    // could easily clear while editing.
    const durationInputs = wrapper.findAll('input[type="number"]').filter((el) => el.attributes('min') === '5')
    await durationInputs[0].setValue('')

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Set a duration of at least 1 minute for every service you ticked.')
  })

  it('shows a reason to continue before a salon type is even chosen', async () => {
    const wrapper = mountStep()
    await flushPromises()

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Pick your salon type to continue.')
  })

  it('clears a stale error banner when the owner picks a different type after a failed save', async () => {
    vi.mocked(api.post).mockRejectedValueOnce({
      response: { status: 422, data: { message: 'Please fix the highlighted services.', errors: {} } },
    })

    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    await priceInputs[0].setValue('12')
    await priceInputs[1].setValue('5')
    await wrapper.findAll('button').find((b) => b.text().includes('Continue')).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Please fix the highlighted services.')

    await wrapper.findAll('button').find((b) => b.text() === 'Change').trigger('click')

    expect(wrapper.text()).not.toContain('Please fix the highlighted services.')
  })
})
