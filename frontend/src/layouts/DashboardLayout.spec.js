import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls, matching the house pattern.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import router from '@/router/index'
import { useAuthStore } from '@/stores/auth'
import DashboardLayout from '@/layouts/DashboardLayout.vue'

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, name: 'Heaven Touch Salon', subscription_plan: 'free' },
  })
}

describe('DashboardLayout sidebar — Finance entry', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue({ data: { data: {} } })
    await router.replace('/dashboard')
  })

  it('offers Finance to an owner', async () => {
    loginAs('owner')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    const link = wrapper.findAll('a').find((a) => a.text() === 'Finance')
    expect(link).toBeDefined()
    expect(link.attributes('href')).toBe('/finance')
  })

  it('hides Finance from a manager', async () => {
    loginAs('manager')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    expect(wrapper.findAll('a').find((a) => a.text() === 'Finance')).toBeUndefined()
  })

  it('hides Finance from staff', async () => {
    loginAs('staff')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    expect(wrapper.findAll('a').find((a) => a.text() === 'Finance')).toBeUndefined()
  })
})

describe('DashboardLayout sidebar — nav groups', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue({ data: { data: {} } })
    await router.replace('/dashboard')
  })

  // The layout is the route record's own component, so the RouterView we
  // mount renders a second copy of the shell inside the first. Read the
  // outermost sidebar only, or every heading is counted twice.
  function groupHeadings(wrapper) {
    return wrapper.get('aside').findAll('[data-nav-group]').map((el) => el.text())
  }

  it('shows every group to an owner', () => {
    loginAs('owner')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    expect(groupHeadings(wrapper)).toEqual(['Operate', 'Business', 'Insight', 'Presence'])
  })

  it('drops a group whose every item is out of the role’s reach', () => {
    loginAs('staff')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    // Gallery is owner/manager work and Settings is owner-only, so Presence
    // disappears for staff. Insight survives on Reviews alone.
    expect(groupHeadings(wrapper)).toEqual(['Operate', 'Business', 'Insight'])
  })

  it('names the salon and its plan in the sidebar footer', () => {
    loginAs('owner')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    const footer = wrapper.get('[data-org-card]')
    expect(footer.text()).toContain('Heaven Touch Salon')
    expect(footer.text()).toContain('Free plan')
  })
})
