import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls; keep everything else real, matching the house
// pattern in StaffView.spec.js.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  }
})

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import FinanceView from '@/views/FinanceView.vue'

function loginAsOwner() {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Owner', role: 'owner' },
    organization: { id: 9, subscription_plan: 'free', currency: 'USD' },
  })
}

// A draft run with one commission line, using the decimal-string money the
// backend actually sends — never plain numbers.
const DRAFT_RUN_SUMMARY = {
  id: 5,
  period_month: '2026-08-01',
  period_label: 'August 2026',
  status: 'draft',
  total_amount: '275.00',
  finalized_at: null,
}

const DRAFT_RUN_DETAIL = {
  ...DRAFT_RUN_SUMMARY,
  lines: [
    {
      id: 11,
      staff_name: 'Ruma',
      pay_type: 'commission',
      commission_rate: '25.00',
      bookings: 4,
      earned_revenue: '1100.00',
      salary_amount: '0.00',
      commission_amount: '275.00',
      total_amount: '275.00',
    },
  ],
}

const FINALIZED_RUN_DETAIL = {
  ...DRAFT_RUN_DETAIL,
  status: 'finalized',
  finalized_at: '2026-08-09T10:00:00+00:00',
}

function mockRuns({ list = [DRAFT_RUN_SUMMARY], detail = DRAFT_RUN_DETAIL } = {}) {
  vi.mocked(api.get)
    .mockReset()
    .mockImplementation((url) => {
      if (url === '/payroll/runs') return Promise.resolve({ data: { data: list } })
      if (url === `/payroll/runs/${detail.id}`) return Promise.resolve({ data: { data: detail } })
      return Promise.resolve({ data: { data: null } })
    })
}

let currentWrapper = null
function mountFinanceView() {
  currentWrapper = mount(FinanceView)
  return currentWrapper
}

describe('FinanceView — Payroll tab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.post).mockReset()
    vi.mocked(api.patch).mockReset()
    vi.mocked(api.delete).mockReset()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
    vi.restoreAllMocks()
  })

  it('loads the newest run automatically and renders its line with formatted currency, not raw decimal strings', async () => {
    loginAsOwner()
    mockRuns()
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/payroll/runs')
    expect(api.get).toHaveBeenCalledWith('/payroll/runs/5')
    expect(wrapper.text()).toContain('Ruma')
    expect(wrapper.text()).toContain('August 2026')
    // Formatted as currency (has a $ sign), not the bare API string "275.00".
    expect(wrapper.text()).toContain('$275.00')
  })

  it('shows only the payroll section on the payroll tab, and switches away when another tab is clicked', async () => {
    loginAsOwner()
    mockRuns()
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(wrapper.text()).toContain('Open payroll')

    const expensesTab = wrapper.findAll('button').find((b) => b.text() === 'Expenses')
    await expensesTab.trigger('click')

    expect(wrapper.text()).not.toContain('Open payroll')
    expect(wrapper.text()).not.toContain('Ruma')
  })

  it('opens a new payroll run for the selected month', async () => {
    loginAsOwner()
    mockRuns({ list: [], detail: DRAFT_RUN_DETAIL })
    vi.mocked(api.post).mockResolvedValue({ data: { data: DRAFT_RUN_DETAIL } })
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(wrapper.text()).toContain('No payroll yet')

    const select = wrapper.find('select')
    const monthValue = select.findAll('option')[0].element.value
    await select.setValue(monthValue)
    await wrapper.findAll('button').find((b) => b.text() === 'Open payroll').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/payroll/runs', { period_month: monthValue })
    expect(wrapper.text()).toContain('Ruma')
  })

  it('lets a draft line be edited, saves it via PATCH, and refreshes so the header total reflects the server', async () => {
    loginAsOwner()
    mockRuns()
    vi.mocked(api.patch).mockResolvedValue({ data: { data: {} } })
    const wrapper = mountFinanceView()
    await flushPromises()

    const salaryInput = wrapper.find('input[type="number"]')
    await salaryInput.setValue('50')
    await salaryInput.trigger('change')
    await flushPromises()

    expect(api.patch).toHaveBeenCalledWith('/payroll/runs/5/lines/11', { salary_amount: 50 })
    // Refetches both the run and the list after a save.
    expect(api.get).toHaveBeenCalledWith('/payroll/runs/5')
    expect(api.get.mock.calls.filter(([url]) => url === '/payroll/runs').length).toBeGreaterThan(1)
  })

  it('finalizes a run after confirmation, and a finalized run shows static amounts with no editable inputs', async () => {
    loginAsOwner()
    mockRuns()
    vi.mocked(api.post).mockResolvedValue({ data: { data: FINALIZED_RUN_DETAIL } })
    const wrapper = mountFinanceView()
    await flushPromises()

    const finalizeButton = wrapper.findAll('button').find((b) => b.text() === 'Finalize')
    await finalizeButton.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(api.post).toHaveBeenCalledWith('/payroll/runs/5/finalize')
    expect(wrapper.findAll('input[type="number"]')).toHaveLength(0)
    expect(wrapper.findAll('button').find((b) => b.text() === 'Finalize')).toBeUndefined()
  })

  it('does not finalize or delete when the confirm dialog is dismissed', async () => {
    loginAsOwner()
    mockRuns()
    window.confirm.mockReturnValue(false)
    const wrapper = mountFinanceView()
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text() === 'Finalize').trigger('click')
    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('deletes a run after confirmation and clears the active run', async () => {
    loginAsOwner()
    mockRuns()
    vi.mocked(api.delete).mockResolvedValue({})
    const wrapper = mountFinanceView()
    await flushPromises()
    vi.mocked(api.get).mockImplementation((url) => {
      if (url === '/payroll/runs') return Promise.resolve({ data: { data: [] } })
      return Promise.resolve({ data: { data: null } })
    })

    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/payroll/runs/5')
    expect(wrapper.text()).toContain('No payroll yet')
  })

  it('renders the parsed error message, not "[object Object]", when loading fails', async () => {
    loginAsOwner()
    vi.mocked(api.get).mockReset().mockRejectedValue({
      response: { status: 500, data: { message: 'Server exploded' } },
    })
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(wrapper.text()).toContain('Server exploded')
    expect(wrapper.text()).not.toContain('[object Object]')
  })
})
