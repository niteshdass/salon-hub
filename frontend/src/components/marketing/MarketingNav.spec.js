import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import MarketingNav from './MarketingNav.vue'

describe('MarketingNav', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('calls the product Glowhub', () => {
    const wrapper = mount(MarketingNav)

    // The wordmark renders as a stylised lowercase "glowhub" (two-tone), so
    // match case-insensitively rather than the capitalised brand spelling.
    expect(wrapper.text().toLowerCase()).toContain('glowhub')
    expect(wrapper.text()).not.toContain('SalonHub')
  })

  it('no longer offers an anchor to a section that does not exist', () => {
    const wrapper = mount(MarketingNav)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).not.toContain('#contact')
    expect(hrefs).toContain('#features')
  })

  it('keeps the customer entrances, demoted', () => {
    const wrapper = mount(MarketingNav)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).toContain('/salons')
    expect(hrefs).toContain('/account/login')
  })

  it('offers registration as the one filled action', () => {
    const wrapper = mount(MarketingNav)
    const filled = wrapper.findAll('a[data-test="cta-primary"]')

    expect(filled.length).toBeGreaterThan(0)
    expect(filled.every((a) => a.attributes('href') === '/register')).toBe(true)
  })

  it('opens and closes the phone menu', async () => {
    const wrapper = mount(MarketingNav)
    const toggle = wrapper.find('button[aria-label="Toggle navigation menu"]')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })
})
