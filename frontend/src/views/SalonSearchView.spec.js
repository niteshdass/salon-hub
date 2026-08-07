import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/discovery', () => ({ searchSalons: vi.fn() }))

const replace = vi.fn()
const route = { query: {} }
vi.mock('vue-router', () => ({
  useRouter: () => ({ replace }),
  useRoute: () => route,
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import { searchSalons } from '@/lib/discovery'
import SalonSearchView from './SalonSearchView.vue'

const salon = (overrides = {}) => ({
  slug: 'chastity-hyde',
  name: 'Chastity Hyde',
  city: 'Sylhet',
  cover_image_url: null,
  logo_url: null,
  currency: 'BDT',
  price_from: '500.00',
  rating: { average: 4.6, count: 12 },
  services: ['Hair cut', 'Hair spa'],
  ...overrides,
})

const results = (rows) => ({ data: rows, meta: { total: rows.length, page: 1, per_page: 12 } })

describe('SalonSearchView', () => {
  beforeEach(() => {
    // MarketingNav (rendered by this view) reads the signed-in session via
    // useSessionLink(), which needs an active Pinia instance even though no
    // test here signs anyone in.
    setActivePinia(createPinia())
    vi.useRealTimers()
    replace.mockReset()
    route.query = {}
    vi.mocked(searchSalons).mockReset()
    vi.mocked(searchSalons).mockResolvedValue(results([salon()]))
  })

  it('lists salons and links each card to its shopfront', async () => {
    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain('Chastity Hyde')
    expect(wrapper.text()).toContain('Sylhet')
    expect(wrapper.find('a[href="/salon/chastity-hyde"]').exists()).toBe(true)
  })

  it('hides the rating of a salon that has too few reviews', async () => {
    vi.mocked(searchSalons).mockResolvedValue(results([salon({ rating: null })]))

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.find('[data-test="rating"]').exists()).toBe(false)
  })

  it('starts from the query in the URL', async () => {
    route.query = { q: 'massage' }

    mount(SalonSearchView)
    await flushPromises()

    expect(vi.mocked(searchSalons)).toHaveBeenCalledWith({ q: 'massage', page: 1 })
  })

  it('searches once after typing settles, and puts the query in the URL', async () => {
    vi.useFakeTimers()
    const wrapper = mount(SalonSearchView)
    await flushPromises()
    vi.mocked(searchSalons).mockClear()

    await wrapper.find('input[type="search"]').setValue('hai')
    await wrapper.find('input[type="search"]').setValue('hair')
    vi.advanceTimersByTime(299)
    expect(vi.mocked(searchSalons)).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(vi.mocked(searchSalons)).toHaveBeenCalledTimes(1)
    expect(vi.mocked(searchSalons)).toHaveBeenCalledWith({ q: 'hair', page: 1 })
    expect(replace).toHaveBeenCalledWith({ query: { q: 'hair' } })
  })

  it('tells a searcher when nothing matched, and offers a way back', async () => {
    route.query = { q: 'xyz' }
    vi.mocked(searchSalons).mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 12 } })

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain('Nothing matches')
    expect(wrapper.find('[data-test="clear"]').exists()).toBe(true)
  })

  it('is honest when no salon is listed at all', async () => {
    vi.mocked(searchSalons).mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 12 } })

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain('just getting started')
    expect(wrapper.find('[data-test="clear"]').exists()).toBe(false)
  })

  it('says so when the search itself fails', async () => {
    vi.mocked(searchSalons).mockRejectedValue(new Error('network'))

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain("Couldn't load salons")
  })
})
