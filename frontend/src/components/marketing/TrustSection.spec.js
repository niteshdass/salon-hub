import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import TrustSection from './TrustSection.vue'

describe('TrustSection', () => {
  it('says plainly that the product is new', () => {
    const wrapper = mount(TrustSection)

    expect(wrapper.find('h2').text()).toContain("We're new")
  })

  it('makes no claim that needs a customer to substantiate it', () => {
    const wrapper = mount(TrustSection)
    const text = wrapper.text()

    expect(text).not.toMatch(/\d+%/)
    expect(text).not.toContain('Loved by')
  })

  it('promises the client list is portable, which is the real objection', () => {
    const wrapper = mount(TrustSection)

    expect(wrapper.text()).toContain("we'll send you every client")
  })

  it('points at the demo salon rather than at a testimonial', () => {
    const wrapper = mount(TrustSection)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).toContain('/salon/demo-salon')
  })
})
