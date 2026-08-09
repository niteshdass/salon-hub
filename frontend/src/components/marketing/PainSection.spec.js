import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PainSection from './PainSection.vue'

describe('PainSection', () => {
  it('lists three of the problems the owner has today and one answer', () => {
    const wrapper = mount(PainSection)
    const terms = wrapper.findAll('dt').map((dt) => dt.text())

    expect(terms).toEqual(['Today', 'Today', 'Today', 'Glowhub'])
  })

  it('names the channels the owner actually loses bookings in', () => {
    const wrapper = mount(PainSection)

    expect(wrapper.text()).toContain('midnight')
    expect(wrapper.text()).toContain('reminded')
  })

  it('gives the answer row the emphasis treatment', () => {
    const wrapper = mount(PainSection)
    const answer = wrapper.findAll('dt').at(-1)

    expect(answer.classes()).toContain('text-brand-600')
  })
})
