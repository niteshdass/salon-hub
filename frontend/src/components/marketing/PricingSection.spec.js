import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import PricingSection from './PricingSection.vue'

describe('PricingSection', () => {
  it('prices in taka, never in dollars', () => {
    const wrapper = mount(PricingSection)

    expect(wrapper.text()).toContain('৳0')
    expect(wrapper.text()).not.toContain('$')
  })

  it('states the free limits the API actually enforces', () => {
    const wrapper = mount(PricingSection)

    expect(wrapper.text()).toContain('1 branch')
    expect(wrapper.text()).toContain('10 staff')
  })

  it('discloses who bills for payments and for reminders', () => {
    const wrapper = mount(PricingSection)

    expect(wrapper.text()).toContain('SSLCommerz')
    expect(wrapper.text()).toContain('Twilio')
  })

  it('sends its one action to registration', () => {
    const wrapper = mount(PricingSection)
    const primaries = wrapper.findAll('a').filter((a) => a.classes().includes('bg-brand-500'))

    expect(primaries).toHaveLength(1)
    expect(primaries[0].attributes('href')).toBe('/register')
  })
})
