import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// House pattern (see src/layouts/DashboardLayout.spec.js): mock only the
// axios calls, keep everything else in '@/lib/api' real.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
// The real router, not a stub: PublicBookingView reads route.params.slug via
// useRoute() and renders bare <RouterLink> tags that only resolve when a
// router plugin is installed. DashboardLayout.spec.js drives its component
// the same way — push the real router to the view's path, then mount with
// it as a global plugin.
import router from '@/router/index'
import PublicBookingView from './PublicBookingView.vue'

// Two levels of `data`: the outer one is axios's response envelope, the
// inner one is Laravel's `response()->json(['data' => [...]])` envelope —
// BookingController::organization() returns both.
const SALON = {
  data: {
    data: {
      name: 'Beauty Queen',
      slug: 'beauty-queen',
      currency: 'USD',
      cover_image_url: null,
      theme_color: null,
      branches: [{ id: 1, name: 'Main branch', city: 'Dhaka', address: '', phone: '' }],
      payment: {
        requires_deposit: false,
        deposit_type: 'none',
        deposit_value: '0.00',
        manual: { enabled: false, account_number: null, instructions: null },
        gateway: { enabled: false, provider: 'none' },
      },
    },
  },
}

const SERVICES = {
  data: {
    data: [
      { id: 1, name: 'Haircut', duration: 30, price: '40.00' },
      { id: 2, name: 'Blow Dry', duration: 20, price: '15.00' },
    ],
  },
}

// Per-URL responses, not a blanket mock: the view fires three different GETs
// on mount/interaction (organization, services, staff), and returning the
// service list for all of them would make every test pass for the wrong
// reason.
function mockApiGet() {
  api.get = vi.fn((url) => {
    if (url.endsWith('/services')) return Promise.resolve(SERVICES)
    if (url.includes('/staff')) return Promise.resolve({ data: { data: [] } })
    if (url.endsWith('/slots')) return Promise.resolve({ data: { data: { date: '2026-08-08', slots: [] } } })
    // Base path with no suffix: the organization/profile call.
    return Promise.resolve(SALON)
  })
}

describe('PublicBookingView service selection', () => {
  let wrapper

  beforeEach(async () => {
    setActivePinia(createPinia())
    mockApiGet()
    await router.replace('/book/beauty-queen')
  })

  // Nothing here unmounts automatically between tests, and a stale instance
  // left reacting to the next test's mocks/router state is how the previous
  // wrapper crashed rendering with a `salon` that had gone undefined.
  afterEach(() => {
    wrapper?.unmount()
  })

  function mountView() {
    wrapper = mount(PublicBookingView, { global: { plugins: [router] } })
    return wrapper
  }

  it('sums duration and price across the selected services', async () => {
    mountView()
    await flushPromises()

    await wrapper.vm.toggleService({ id: 1, name: 'Haircut', duration: 30, price: '40.00' })
    await wrapper.vm.toggleService({ id: 2, name: 'Blow Dry', duration: 20, price: '15.00' })

    expect(wrapper.vm.selectedServiceIds).toEqual([1, 2])
    expect(wrapper.vm.totalDuration).toBe(50)
    expect(wrapper.vm.totalPrice).toBe(55)
  })

  it('clears the chosen staff and slot when the selection changes', async () => {
    mountView()
    await flushPromises()

    await wrapper.vm.toggleService({ id: 1, name: 'Haircut', duration: 30, price: '40.00' })
    wrapper.vm.selectedStaff = { id: 9, name: 'Alex' }
    wrapper.vm.selectedSlot = '10:00'

    await wrapper.vm.toggleService({ id: 2, name: 'Blow Dry', duration: 20, price: '15.00' })

    expect(wrapper.vm.selectedStaff).toBeNull()
    expect(wrapper.vm.selectedSlot).toBeNull()
  })
})
