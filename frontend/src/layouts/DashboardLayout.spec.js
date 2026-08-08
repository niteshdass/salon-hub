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
    organization: { id: 9, subscription_plan: 'free' },
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
