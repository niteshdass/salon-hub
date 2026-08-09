import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import FaqSection from './FaqSection.vue'

describe('FaqSection', () => {
  it('answers the objections a Messenger-run salon actually has', () => {
    const wrapper = mount(FaqSection)
    const questions = wrapper.findAll('button').map((b) => b.text())

    expect(questions.some((q) => q.includes('Messenger'))).toBe(true)
    expect(questions.some((q) => q.includes('client list'))).toBe(true)
    expect(questions.some((q) => q.includes('free'))).toBe(true)
  })

  it('never calls the product by its old name', () => {
    const wrapper = mount(FaqSection)

    expect(wrapper.text()).not.toContain('SalonHub')
    expect(wrapper.text()).toContain('Glowhub')
  })

  it('opens the first answer and closes it again on a second click', async () => {
    const wrapper = mount(FaqSection)
    const first = wrapper.findAll('button')[0]

    expect(first.attributes('aria-expanded')).toBe('true')
    await first.trigger('click')
    expect(first.attributes('aria-expanded')).toBe('false')
  })
})
