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

  it('highlights only the row a 422 blames, by its position among the ticked rows sent', async () => {
    // Three ticked rows sent to the server as rows[0..2]; the server blames
    // index 1 (Beard trim). Only Beard trim's row may end up flagged — not
    // Hair cut, not Shave, and not the form as a whole.
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
            ],
          },
        ],
      },
    })
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

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    await priceInputs[0].setValue('12')
    await priceInputs[1].setValue('-1')
    await priceInputs[2].setValue('8')

    await wrapper.findAll('button').find((b) => b.text().includes('Continue')).trigger('click')
    await flushPromises()

    const rows = wrapper.findAll('li')
    expect(rows[0].text()).not.toContain('Price must be at least 0.')
    expect(rows[1].text()).toContain('Price must be at least 0.')
    expect(rows[2].text()).not.toContain('Price must be at least 0.')

    expect(rows[0].classes()).not.toContain('ring-rose-300')
    expect(rows[1].classes()).toContain('ring-rose-300')
    expect(rows[2].classes()).not.toContain('ring-rose-300')
  })
})
