import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import MarketingCta from './MarketingCta.vue'

describe('MarketingCta', () => {
  it('links to its destination and shows its label', () => {
    const wrapper = mount(MarketingCta, { props: { to: '/register', label: 'Register free' } })

    expect(wrapper.find('a').attributes('href')).toBe('/register')
    expect(wrapper.text()).toContain('Register free')
  })

  it('gives the primary variant the filled ink treatment and an arrow', () => {
    const wrapper = mount(MarketingCta, { props: { to: '/register', label: 'Register free' } })

    expect(wrapper.find('a').classes()).toContain('bg-ink')
    expect(wrapper.find('svg').exists()).toBe(true)
  })

  it('gives the secondary variant an outline and no arrow', () => {
    const wrapper = mount(MarketingCta, {
      props: { to: '/salon/demo-salon', label: 'See a live booking page', variant: 'secondary' },
    })

    expect(wrapper.find('a').classes()).not.toContain('bg-ink')
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('stretches full width when block is set, for the phone layout', () => {
    const wrapper = mount(MarketingCta, { props: { to: '/register', label: 'Register free', block: true } })

    expect(wrapper.find('a').classes()).toContain('w-full')
  })
})
