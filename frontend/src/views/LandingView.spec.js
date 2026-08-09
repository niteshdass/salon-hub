import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))
// MarketingFooter posts its contact form through the shared api client, and
// MarketingNav/StickyMobileCta reach useSessionLink() -> the auth store,
// which reads TOKEN_KEY from this same module at construction. A bare stub
// mock drops that export and crashes the store, so keep the real exports
// (TOKEN_KEY included) and only replace the network call.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { ...actual.default, post: vi.fn() } }
})

import LandingView from './LandingView.vue'

const EXPECTED_ORDER = [
  'MarketingNav',
  'HeroSection',
  'PainSection',
  'ProductTourSection',
  'HowItWorksSection',
  'PricingSection',
  'TrustSection',
  'FaqSection',
  'CtaSection',
  'MarketingFooter',
  'StickyMobileCta',
]

describe('LandingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    // The sticky bar observes the hero; jsdom has no IntersectionObserver.
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        observe() {}
        disconnect() {}
      },
    )
  })

  it('argues in the intended order', () => {
    const wrapper = mount(LandingView)
    const rendered = EXPECTED_ORDER.filter((name) => wrapper.findComponent({ name }).exists())

    expect(rendered).toEqual(EXPECTED_ORDER)
  })

  it('never calls the product by its old name', () => {
    const wrapper = mount(LandingView)

    expect(wrapper.text()).not.toContain('SalonHub')
  })

  it('quotes no price in dollars', () => {
    const wrapper = mount(LandingView)

    expect(wrapper.text()).not.toContain('$')
  })

  it('points every filled call to action at registration', () => {
    const wrapper = mount(LandingView)
    // Primary CTAs are identified by data-test, not by fill colour: the
    // closing band sits on a dark section and inverts to a paper fill so it
    // stays visible, so colour alone can no longer tell "filled" apart from
    // "not filled". One in the nav (desktop), one in the nav (mobile sheet),
    // and one each in the hero, pricing card and closing band = 5.
    const filled = wrapper.findAll('a[data-test="cta-primary"]')

    expect(filled).toHaveLength(5)
    expect(filled.every((a) => a.attributes('href') === '/register')).toBe(true)
  })

  it('keeps every anchor in the nav pointing at a section that exists', () => {
    const wrapper = mount(LandingView)
    const anchors = wrapper
      .findAll('a')
      .map((a) => a.attributes('href'))
      .filter((href) => href?.startsWith('#') && href !== '#top')

    for (const href of new Set(anchors)) {
      expect(wrapper.find(`section${href}`).exists()).toBe(true)
    }
  })
})
