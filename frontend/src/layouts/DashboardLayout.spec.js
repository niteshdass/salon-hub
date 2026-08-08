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

// The layout is the authenticated route record's own component, so an
// unstubbed RouterView renders a second copy of the shell inside the first.
// Stubbing it leaves exactly one sidebar to assert against.
function mountShell() {
  return mount(DashboardLayout, {
    global: { plugins: [router], stubs: { RouterView: true } },
  })
}

describe('DashboardLayout sidebar — Finance entry', () => {
  beforeEach(async () => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue({ data: { data: {} } })
    await router.replace('/dashboard')
  })

  it('offers Finance to an owner', async () => {
    loginAs('owner')
    const wrapper = mountShell()

    const link = wrapper.findAll('a').find((a) => a.text() === 'Finance')
    expect(link).toBeDefined()
    expect(link.attributes('href')).toBe('/finance')
  })

  it('hides Finance from a manager', async () => {
    loginAs('manager')
    const wrapper = mountShell()

    expect(wrapper.findAll('a').find((a) => a.text() === 'Finance')).toBeUndefined()
  })

  it('hides Finance from staff', async () => {
    loginAs('staff')
    const wrapper = mountShell()

    expect(wrapper.findAll('a').find((a) => a.text() === 'Finance')).toBeUndefined()
  })
})

describe('DashboardLayout sidebar — nav groups', () => {
  beforeEach(async () => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue({ data: { data: {} } })
    await router.replace('/dashboard')
  })

  function groupHeadings(wrapper) {
    return wrapper.findAll('[data-nav-group]').map((el) => el.text())
  }

  it('shows every group to an owner', () => {
    loginAs('owner')
    const wrapper = mountShell()

    expect(groupHeadings(wrapper)).toEqual(['Operate', 'Business', 'Insight', 'Presence'])
  })

  it('files every page under the group it belongs to', () => {
    loginAs('owner')
    const wrapper = mountShell()

    const grouped = wrapper.findAll('nav > section').map((section) => [
      section.get('[data-nav-group]').text(),
      section.findAll('a').map((link) => link.text()),
    ])

    expect(grouped).toEqual([
      ['Operate', ['Dashboard', 'Appointments', 'Calendar']],
      ['Business', ['Branches', 'Services', 'Staff', 'Customers']],
      ['Insight', ['Reports', 'Finance', 'Reviews']],
      ['Presence', ['Gallery', 'Settings']],
    ])
  })

  it('drops a group whose every item is out of the role’s reach', () => {
    loginAs('staff')
    const wrapper = mountShell()

    // Gallery is owner/manager work and Settings is owner-only, so Presence
    // disappears for staff. Insight survives on Reviews alone.
    expect(groupHeadings(wrapper)).toEqual(['Operate', 'Business', 'Insight'])
  })

  it('names the salon and its plan in the sidebar footer', () => {
    loginAs('owner')
    const wrapper = mountShell()

    const footer = wrapper.get('[data-org-card]')
    expect(footer.text()).toContain('Heaven Touch Salon')
    expect(footer.text()).toContain('Free plan')
  })
})
