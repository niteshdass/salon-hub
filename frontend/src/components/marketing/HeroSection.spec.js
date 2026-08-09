import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import HeroSection from './HeroSection.vue'

describe('HeroSection', () => {
  it('leads with the after-hours booking problem', () => {
    const wrapper = mount(HeroSection)

    expect(wrapper.find('h1').text()).toContain('11pm')
  })

  it('offers exactly one primary action, to registration', () => {
    const wrapper = mount(HeroSection)
    const primaries = wrapper.findAll('a[data-test="cta-primary"]')

    expect(primaries).toHaveLength(1)
    expect(primaries[0].attributes('href')).toBe('/register')
  })

  it('sends the second action to the demo salon rather than to a signup', () => {
    const wrapper = mount(HeroSection)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).toContain('/salon/demo-salon')
  })

  it('prices the mock in taka and never in dollars', () => {
    const wrapper = mount(HeroSection)

    expect(wrapper.text()).toContain('৳')
    expect(wrapper.text()).not.toContain('$')
  })

  it('keeps the proof line inside the card, so nothing hangs off the viewport', () => {
    const wrapper = mount(HeroSection)
    const html = wrapper.html()

    expect(wrapper.text()).toContain('booked at 11:04pm')
    // Bounded so an unrelated offset like the floating card's -top-4 cannot
    // false-match. The 360px overflow these two exact offsets caused before
    // must not come back.
    expect(html).not.toMatch(/-right-3(?!\d)/)
    expect(html).not.toMatch(/-left-3(?!\d)/)
  })

  it('keeps the floating "New booking" card hidden until the lg breakpoint', () => {
    const wrapper = mount(HeroSection)

    expect(wrapper.text()).toContain('New booking')
    expect(wrapper.text()).toContain('৳500 advance')

    const card = wrapper.findAll('div').find((d) => d.text() === 'New booking৳500 advance')
    expect(card).toBeTruthy()
    expect(card.classes()).toContain('hidden')
    expect(card.classes()).toContain('lg:flex')
  })
})
