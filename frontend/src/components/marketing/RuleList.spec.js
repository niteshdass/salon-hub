import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import RuleList from './RuleList.vue'

const ITEMS = [
  { term: 'Today', text: 'You reply at 9am. She booked somewhere else.' },
  { term: 'Glowhub', text: 'She books herself.', strong: true },
]

describe('RuleList', () => {
  it('renders one row per item with its term and text', () => {
    const wrapper = mount(RuleList, { props: { items: ITEMS } })

    expect(wrapper.findAll('dt')).toHaveLength(2)
    expect(wrapper.findAll('dt')[0].text()).toBe('Today')
    expect(wrapper.findAll('dd')[1].text()).toBe('She books herself.')
  })

  it('marks the strong row so it reads as the answer, not another complaint', () => {
    const wrapper = mount(RuleList, { props: { items: ITEMS } })

    expect(wrapper.findAll('dt')[0].classes()).toContain('text-ink/65')
    expect(wrapper.findAll('dt')[1].classes()).toContain('text-brand-600')
  })
})
