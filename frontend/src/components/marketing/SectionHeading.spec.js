import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SectionHeading from './SectionHeading.vue'

describe('SectionHeading', () => {
  it('renders the eyebrow and the title as an h2', () => {
    const wrapper = mount(SectionHeading, {
      props: { eyebrow: 'Pricing', title: '৳0. Everything above.' },
    })

    expect(wrapper.text()).toContain('Pricing')
    expect(wrapper.find('h2').text()).toBe('৳0. Everything above.')
  })

  it('omits the lede paragraph when there is nothing to say', () => {
    const wrapper = mount(SectionHeading, { props: { eyebrow: 'FAQ', title: 'Questions, answered.' } })

    expect(wrapper.find('p.lede').exists()).toBe(false)
  })

  it('renders the lede when given one', () => {
    const wrapper = mount(SectionHeading, {
      props: { eyebrow: 'What you get', title: 'Three things, done properly.', lede: 'No add-ons.' },
    })

    expect(wrapper.find('p.lede').text()).toBe('No add-ons.')
  })

  it('centres the block and closes the eyebrow rule when align is center', () => {
    const wrapper = mount(SectionHeading, {
      props: { eyebrow: 'How it works', title: 'Live in three steps.', align: 'center' },
    })

    expect(wrapper.classes()).toContain('text-center')
    expect(wrapper.findAll('[data-rule]')).toHaveLength(2)
  })
})
