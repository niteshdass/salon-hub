import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ProductTourSection from './ProductTourSection.vue'

describe('ProductTourSection', () => {
  it('anchors the Features nav link', () => {
    const wrapper = mount(ProductTourSection)

    expect(wrapper.find('section').attributes('id')).toBe('features')
  })

  it('tells three numbered stories, not six icon cards', () => {
    const wrapper = mount(ProductTourSection)
    const headings = wrapper.findAll('h3').map((h) => h.text())

    expect(headings).toHaveLength(3)
    expect(headings[0]).toContain('A booking page of your own')
    expect(headings[1]).toContain('Reminders that get read')
    expect(headings[2]).toContain('Money you can see')
  })

  it('shows the shareable address in the shape an owner is actually given', () => {
    const wrapper = mount(ProductTourSection)

    expect(wrapper.text()).toContain('your-salon.')
  })

  it('sweeps the remaining features into one line rather than a second grid', () => {
    const wrapper = mount(ProductTourSection)
    const terms = wrapper.findAll('dt').map((dt) => dt.text())

    expect(terms).toEqual(['Also included'])
    expect(wrapper.find('dd').text()).toContain('Payroll')
  })

  it('reads text before mock on a phone and only alternates from lg up', () => {
    const wrapper = mount(ProductTourSection)
    const blocks = wrapper.findAll('[data-tour-block]')

    expect(blocks).toHaveLength(3)
    // The reversed block flips only at lg; source order stays text-then-mock.
    expect(blocks[1].html()).toContain('lg:order-2')
  })
})
