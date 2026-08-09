import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import { TOKEN_KEY } from '@/lib/api'
import StickyMobileCta from './StickyMobileCta.vue'

// jsdom ships no IntersectionObserver. This stub keeps the last callback so a
// test can say "the hero just scrolled away" without any scrolling.
let fire
const observe = vi.fn()
const disconnect = vi.fn()

beforeEach(() => {
  // The stores read their token from localStorage at construction, so a
  // session is installed the way sessionLink.spec.js installs one.
  localStorage.clear()
  setActivePinia(createPinia())
  observe.mockClear()
  disconnect.mockClear()
  document.body.innerHTML = '<div id="top"></div>'
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      constructor(cb) {
        fire = cb
      }
      observe = observe
      disconnect = disconnect
    },
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('StickyMobileCta', () => {
  it('stays hidden while the hero is still on screen', () => {
    const wrapper = mount(StickyMobileCta)

    fire([{ isIntersecting: true }])
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('appears once the hero has scrolled away, pointing at registration', async () => {
    const wrapper = mount(StickyMobileCta)

    fire([{ isIntersecting: false }])
    await wrapper.vm.$nextTick()

    expect(wrapper.find('a').attributes('href')).toBe('/register')
  })

  it('is a phone-only device', async () => {
    const wrapper = mount(StickyMobileCta)

    fire([{ isIntersecting: false }])
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-sticky-cta]').classes()).toContain('lg:hidden')
  })

  it('never nags a visitor who is already signed in', async () => {
    localStorage.setItem(TOKEN_KEY, 'staff-token')
    setActivePinia(createPinia())

    const wrapper = mount(StickyMobileCta)
    fire?.([{ isIntersecting: false }])
    await wrapper.vm.$nextTick()

    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('stops observing when it goes away', () => {
    const wrapper = mount(StickyMobileCta)

    wrapper.unmount()
    expect(disconnect).toHaveBeenCalled()
  })
})
