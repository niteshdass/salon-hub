import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, DOMWrapper } from '@vue/test-utils'
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
      tips_amount: '0.00',
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
      if (url === '/expenses') return Promise.resolve({ data: { data: [] } })
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

  it('shows a tip in its own Tips column, separate from the commission it never enters', async () => {
    loginAsOwner()
    mockRuns({
      detail: {
        ...DRAFT_RUN_DETAIL,
        lines: [{ ...DRAFT_RUN_DETAIL.lines[0], tips_amount: '15.00', total_amount: '290.00' }],
      },
    })
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(wrapper.text()).toContain('Tips')
    expect(wrapper.text()).toContain('$15.00')
    // Commission stays 275 — the tip was added on top, not folded into it.
    expect(wrapper.text()).toContain('$275.00')
    expect(wrapper.text()).toContain('$290.00')
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

  it('disables Finalize, Delete and Open payroll while a finalize is in flight, and re-enables afterwards', async () => {
    loginAsOwner()
    mockRuns()
    let release
    vi.mocked(api.post).mockImplementation(
      () => new Promise((resolve) => { release = () => resolve({ data: { data: FINALIZED_RUN_DETAIL } }) }),
    )
    const wrapper = mountFinanceView()
    await flushPromises()

    const byText = (label) => wrapper.findAll('button').find((b) => b.text() === label)
    expect(byText('Finalize').attributes('disabled')).toBeUndefined()

    await byText('Finalize').trigger('click')

    expect(byText('Finalize').attributes('disabled')).toBeDefined()
    expect(byText('Delete').attributes('disabled')).toBeDefined()
    expect(byText('Open payroll').attributes('disabled')).toBeDefined()

    release()
    await flushPromises()

    expect(byText('Delete').attributes('disabled')).toBeUndefined()
    expect(byText('Open payroll').attributes('disabled')).toBeUndefined()
  })

  it('sends only one finalize request when Finalize is double-clicked', async () => {
    loginAsOwner()
    mockRuns()
    // Never settles: the button must stay dead for the whole round trip, which
    // is exactly the window a double-click lands in.
    vi.mocked(api.post).mockImplementation(() => new Promise(() => {}))
    const wrapper = mountFinanceView()
    await flushPromises()

    const finalize = wrapper.findAll('button').find((b) => b.text() === 'Finalize')

    // Two clicks in the same tick, before Vue has flushed :disabled onto the
    // element — the shape a real double-click takes. Only the handler's own
    // re-entry guard can refuse the second one.
    finalize.element.click()
    finalize.element.click()
    await flushPromises()

    // And once the disabled attribute has landed, a later click is refused too.
    await finalize.trigger('click')

    expect(api.post).toHaveBeenCalledTimes(1)
  })

  it('keeps a payroll load failure on screen when the expense load that follows it succeeds', async () => {
    loginAsOwner()
    // onMounted loads payroll and then expenses. A single shared banner meant
    // the second, successful load wiped the first one's failure before anyone
    // could read it.
    vi.mocked(api.get).mockReset().mockImplementation((url) => {
      if (url === '/payroll/runs') {
        return Promise.reject({ response: { status: 500, data: { message: 'Payroll is down' } } })
      }
      if (url === '/expenses') return Promise.resolve({ data: { data: [] } })
      return Promise.resolve({ data: { data: null } })
    })
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(wrapper.find('.text-rose-700').text()).toBe('Payroll is down')
  })

  it('renders exactly parseApiError(...).message in the banner, when loading fails', async () => {
    loginAsOwner()
    vi.mocked(api.get).mockReset().mockRejectedValue({
      response: { status: 500, data: { message: 'Server exploded' } },
    })
    const wrapper = mountFinanceView()
    await flushPromises()

    // An exact match on the banner's own text, not a substring check against
    // the whole page: if the catch block assigned the parsed error *object*
    // instead of its .message, Vue's toDisplayString would JSON.stringify it
    // to something that still contains the substring "Server exploded" (and
    // never the literal "[object Object]"), so a substring/exclusion
    // assertion here would pass on that regression too. Pinning the exact
    // string rules that out.
    expect(wrapper.find('.text-rose-700').text()).toBe('Server exploded')
  })
})

// Matches FinanceView's own startOfMonth()/today() helpers, which are tied
// to the wall clock (not injectable) — mirrored here so assertions on the
// default filter window stay exact rather than approximate.
function startOfMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function today() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const OWN_EXPENSE = {
  id: 21,
  category: 'rent',
  expense_date: '2026-08-05',
  amount: '1050.00',
  note: 'August rent',
  branch_id: 3,
  payroll_run_id: null,
  is_locked: false,
}

const PAYROLL_EXPENSE = {
  id: 22,
  category: 'salary',
  expense_date: '2026-08-01',
  amount: '2000.00',
  note: null,
  branch_id: 3,
  payroll_run_id: 5,
  is_locked: true,
}

function mockExpensesAndEmptyRuns(list = [OWN_EXPENSE, PAYROLL_EXPENSE]) {
  vi.mocked(api.get)
    .mockReset()
    .mockImplementation((url) => {
      if (url === '/payroll/runs') return Promise.resolve({ data: { data: [] } })
      if (url === '/expenses') return Promise.resolve({ data: { data: list } })
      return Promise.resolve({ data: { data: null } })
    })
}

describe('FinanceView — Expenses tab', () => {
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

  async function openExpensesTab(wrapper) {
    const expensesTab = wrapper.findAll('button').find((b) => b.text() === 'Expenses')
    await expensesTab.trigger('click')
  }

  it('loads expenses on mount for the current month by default, formats currency, and totals the visible rows', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns()
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/expenses', {
      params: { from: startOfMonth(), to: today(), category: undefined },
    })

    await openExpensesTab(wrapper)

    // Formatted as currency, not the bare API strings "1050.00"/"2000.00".
    expect(wrapper.text()).toContain('$1,050.00')
    expect(wrapper.text()).toContain('$2,000.00')
    // Total = 1050 + 2000 = 3050, also formatted as currency.
    expect(wrapper.text()).toContain('$3,050.00')
  })

  it('shows a locked label with a lock icon and no Edit/Delete for a payroll-generated expense, and both controls for a manual one', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns()
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    const rows = wrapper.findAll('tbody tr')
    const ownRow = rows.find((r) => r.text().includes('August rent'))
    const payrollRow = rows.find((r) => r.text().includes('From payroll'))

    expect(ownRow.text()).toContain('Edit')
    expect(ownRow.text()).toContain('Delete')
    expect(payrollRow.text()).toContain('From payroll')
    expect(payrollRow.find('svg').exists()).toBe(true)

    const controls = payrollRow.findAll('button').map((b) => b.text())
    expect(controls).not.toContain('Edit')
    expect(controls).not.toContain('Delete')
  })

  it('links a locked row to its payroll run, switching to the Payroll tab and opening that run', async () => {
    loginAsOwner()
    vi.mocked(api.get)
      .mockReset()
      .mockImplementation((url) => {
        if (url === '/payroll/runs') return Promise.resolve({ data: { data: [] } })
        if (url === '/payroll/runs/5') return Promise.resolve({ data: { data: FINALIZED_RUN_DETAIL } })
        if (url === '/expenses') return Promise.resolve({ data: { data: [OWN_EXPENSE, PAYROLL_EXPENSE] } })
        return Promise.resolve({ data: { data: null } })
      })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    const payrollRow = wrapper.findAll('tbody tr').find((r) => r.text().includes('From payroll'))
    const link = payrollRow.findAll('button').find((b) => b.text().includes('From payroll'))
    expect(link).toBeTruthy()

    await link.trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/payroll/runs/5')
    // On the Payroll tab now, with run 5 open — the answer to "so what do I
    // edit instead?".
    expect(wrapper.text()).toContain('Open payroll')
    expect(wrapper.text()).toContain('August 2026')
    expect(wrapper.text()).toContain('Ruma')
  })

  it('offers salary as a filter but never as a category to log by hand', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([])
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    const selects = wrapper.findAll('select')
    const filterValues = selects[selects.length - 1].findAll('option').map((o) => o.element.value)
    expect(filterValues).toContain('salary')

    await wrapper.findAll('button').find((b) => b.text() === 'Add expense').trigger('click')
    await flushPromises()

    const body = new DOMWrapper(document.body)
    const modalValues = body.find('select').findAll('option').map((o) => o.element.value)
    // `salary` belongs to payroll: a hand-logged one alongside a finalized run
    // is exactly the double-count payroll_run_id exists to prevent.
    expect(modalValues).not.toContain('salary')
    expect(modalValues).toContain('rent')
    expect(modalValues).toContain('supplies')
  })

  it('clears a stale page banner when the expense modal is opened', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([OWN_EXPENSE])
    vi.mocked(api.delete).mockRejectedValue({
      response: { status: 500, data: { message: 'Could not reach the server' } },
    })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()
    expect(wrapper.find('.text-rose-700').text()).toBe('Could not reach the server')

    await wrapper.findAll('button').find((b) => b.text() === 'Add expense').trigger('click')
    await flushPromises()

    expect(wrapper.find('.text-rose-700').exists()).toBe(false)
  })

  it('disables Save while the expense save is in flight, and sends only one request on a double-click', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([])
    vi.mocked(api.post).mockImplementation(() => new Promise(() => {}))
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Add expense').trigger('click')
    await flushPromises()

    const body = new DOMWrapper(document.body)
    const save = () => body.findAll('button').find((b) => b.text() === 'Save')
    expect(save().attributes('disabled')).toBeUndefined()

    // Same tick, before :disabled has been flushed: the handler guard holds.
    save().element.click()
    save().element.click()
    await flushPromises()

    expect(save().attributes('disabled')).toBeDefined()

    await save().trigger('click')
    expect(api.post).toHaveBeenCalledTimes(1)
  })

  it('filters by category, refetching with the chosen value', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([OWN_EXPENSE])
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    const selects = wrapper.findAll('select')
    const categorySelect = selects[selects.length - 1]
    await categorySelect.setValue('rent')
    await flushPromises()

    expect(api.get).toHaveBeenLastCalledWith('/expenses', {
      params: { from: startOfMonth(), to: today(), category: 'rent' },
    })
  })

  it('creates a new expense via POST, closes the modal, and reloads the list', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([])
    vi.mocked(api.post).mockResolvedValue({ data: { data: OWN_EXPENSE } })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Add expense').trigger('click')
    await flushPromises()

    const body = new DOMWrapper(document.body)
    expect(body.find('h2').text()).toBe('Add expense')

    await body.find('input[type="date"]').setValue('2026-08-06')
    await body.find('input[type="number"]').setValue('75.50')
    await body.find('input[type="text"]').setValue('Cleaning supplies')
    await body.findAll('button').find((b) => b.text() === 'Save').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/expenses', {
      category: 'supplies',
      expense_date: '2026-08-06',
      amount: 75.5,
      note: 'Cleaning supplies',
    })
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
    expect(api.get.mock.calls.filter(([url]) => url === '/expenses').length).toBeGreaterThan(1)
  })

  it('edits an existing expense via PATCH, prefilling the form from the clicked row', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([OWN_EXPENSE])
    vi.mocked(api.patch).mockResolvedValue({ data: { data: OWN_EXPENSE } })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Edit').trigger('click')
    await flushPromises()

    const body = new DOMWrapper(document.body)
    expect(body.find('h2').text()).toBe('Edit expense')
    expect(body.find('input[type="number"]').element.value).toBe('1050.00')

    await body.find('input[type="number"]').setValue('1200')
    await body.findAll('button').find((b) => b.text() === 'Save').trigger('click')
    await flushPromises()

    expect(api.patch).toHaveBeenCalledWith('/expenses/21', {
      category: 'rent',
      expense_date: '2026-08-05',
      amount: 1200,
      note: 'August rent',
    })
  })

  it('shows per-field 422 errors in the modal without touching the page banner', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([])
    vi.mocked(api.post).mockRejectedValue({
      response: { status: 422, data: { message: 'The given data was invalid.', errors: { amount: ['The amount must be greater than 0.'] } } },
    })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Add expense').trigger('click')
    await flushPromises()
    const body = new DOMWrapper(document.body)
    await body.findAll('button').find((b) => b.text() === 'Save').trigger('click')
    await flushPromises()

    expect(body.text()).toContain('The amount must be greater than 0.')
    expect(wrapper.find('.text-rose-700').exists()).toBe(false)
  })

  it('renders exactly parseApiError(...).message in the banner when a save is rejected outside a 422 (e.g. the payroll-lock refusal)', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([OWN_EXPENSE])
    vi.mocked(api.patch).mockRejectedValue({
      response: { status: 422, data: { message: 'This expense comes from a payroll run. Change the run instead.' } },
    })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Edit').trigger('click')
    await flushPromises()
    const body = new DOMWrapper(document.body)
    await body.findAll('button').find((b) => b.text() === 'Save').trigger('click')
    await flushPromises()

    expect(wrapper.find('.text-rose-700').text()).toBe('This expense comes from a payroll run. Change the run instead.')
  })

  it('deletes an expense after confirmation and reloads the list', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([OWN_EXPENSE])
    vi.mocked(api.delete).mockResolvedValue({})
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(api.delete).toHaveBeenCalledWith('/expenses/21')
    expect(api.get.mock.calls.filter(([url]) => url === '/expenses').length).toBeGreaterThan(1)
  })

  it('does not delete when the confirm dialog is dismissed', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([OWN_EXPENSE])
    window.confirm.mockReturnValue(false)
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()

    expect(api.delete).not.toHaveBeenCalled()
  })

  it('shows the empty state when no expenses fall in range', async () => {
    loginAsOwner()
    mockExpensesAndEmptyRuns([])
    const wrapper = mountFinanceView()
    await flushPromises()
    await openExpensesTab(wrapper)

    expect(wrapper.text()).toContain('No expenses in this range.')
  })
})

const PROFIT_DATA = {
  earned: 5000,
  expenses_total: 3050,
  expenses_by_category: [
    { category: 'salary', amount: 2000, share_pct: 65.6 },
    { category: 'rent', amount: 1050, share_pct: 34.4 },
  ],
  net_profit: 1950,
}

const LOSING_PROFIT_DATA = {
  earned: 500,
  expenses_total: 3050,
  expenses_by_category: [
    { category: 'salary', amount: 2000, share_pct: 65.6 },
    { category: 'rent', amount: 1050, share_pct: 34.4 },
  ],
  net_profit: -2550,
}

// Stubs every URL FinanceView can hit while the Profit tab is exercised —
// following the file's own convention of an explicit stub per URL rather
// than a fallback that could mask a missing one.
function mockProfit({ profit = PROFIT_DATA } = {}) {
  vi.mocked(api.get)
    .mockReset()
    .mockImplementation((url) => {
      if (url === '/payroll/runs') return Promise.resolve({ data: { data: [] } })
      if (url === '/expenses') return Promise.resolve({ data: { data: [] } })
      if (url === '/reports') return Promise.resolve({ data: { data: { profit } } })
      return Promise.resolve({ data: { data: null } })
    })
}

async function openProfitTab(wrapper) {
  const profitTab = wrapper.findAll('button').find((b) => b.text() === 'Profit')
  await profitTab.trigger('click')
  await flushPromises()
}

async function clickTab(wrapper, label) {
  await wrapper.findAll('button').find((b) => b.text() === label).trigger('click')
  await flushPromises()
}

function callsTo(url) {
  return api.get.mock.calls.filter(([called]) => called === url).length
}

// Every tab live at once: a draft run to finalize or delete, one manual
// expense to edit or delete, and a profit block — the state the cross-tab
// staleness bugs actually live in.
function mockEverything() {
  vi.mocked(api.get)
    .mockReset()
    .mockImplementation((url) => {
      if (url === '/payroll/runs') return Promise.resolve({ data: { data: [DRAFT_RUN_SUMMARY] } })
      if (url === '/payroll/runs/5') return Promise.resolve({ data: { data: DRAFT_RUN_DETAIL } })
      if (url === '/expenses') return Promise.resolve({ data: { data: [OWN_EXPENSE] } })
      if (url === '/reports') return Promise.resolve({ data: { data: { profit: PROFIT_DATA } } })
      return Promise.resolve({ data: { data: null } })
    })
}

describe('FinanceView — Profit tab', () => {
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

  it('does not load the reports endpoint until the Profit tab is opened', async () => {
    loginAsOwner()
    mockProfit()
    const wrapper = mountFinanceView()
    await flushPromises()

    expect(api.get).not.toHaveBeenCalledWith('/reports', expect.anything())

    await openProfitTab(wrapper)

    expect(api.get).toHaveBeenCalledWith('/reports', {
      params: { from: startOfMonth(), to: today() },
    })
  })

  it('renders earned, expenses, and a positive net profit in green', async () => {
    loginAsOwner()
    mockProfit()
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    expect(wrapper.text()).toContain('$5,000.00')
    expect(wrapper.text()).toContain('$3,050.00')
    expect(wrapper.text()).toContain('$1,950.00')

    const netEls = wrapper.findAll('.text-emerald-600, .text-rose-600')
    const netEl = netEls.find((el) => el.text() === '$1,950.00')
    expect(netEl).toBeTruthy()
    expect(netEl.classes()).toContain('text-emerald-600')
    expect(netEl.classes()).not.toContain('text-rose-600')
  })

  it('renders a negative net profit in red, not green', async () => {
    loginAsOwner()
    mockProfit({ profit: LOSING_PROFIT_DATA })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    // Formatted as a negative currency amount.
    expect(wrapper.text()).toContain('-$2,550.00')

    const netEls = wrapper.findAll('.text-emerald-600, .text-rose-600')
    const netEl = netEls.find((el) => el.text() === '-$2,550.00')
    expect(netEl).toBeTruthy()
    expect(netEl.classes()).toContain('text-rose-600')
    expect(netEl.classes()).not.toContain('text-emerald-600')
  })

  it('renders one row per expense category with its share, and totals match the header', async () => {
    loginAsOwner()
    mockProfit()
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('salary')
    expect(rows[0].text()).toContain('$2,000.00')
    expect(rows[0].text()).toContain('65.6%')
    expect(rows[1].text()).toContain('rent')
    expect(rows[1].text()).toContain('$1,050.00')
    expect(rows[1].text()).toContain('34.4%')
  })

  it('shows an empty-expenses message instead of a table when the category list is empty', async () => {
    loginAsOwner()
    mockProfit({
      profit: { earned: 5000, expenses_total: 0, expenses_by_category: [], net_profit: 5000 },
    })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).toContain('No expenses in this range — net profit is everything earned.')
  })

  it('refetches with the new range when the From/To pickers change', async () => {
    loginAsOwner()
    mockProfit()
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    const fromInput = wrapper.findAll('input[type="date"]')[0]
    await fromInput.setValue('2026-01-01')
    await fromInput.trigger('change')
    await flushPromises()

    expect(api.get).toHaveBeenLastCalledWith('/reports', {
      params: { from: '2026-01-01', to: today() },
    })

    const toInput = wrapper.findAll('input[type="date"]')[1]
    await toInput.setValue('2026-12-31')
    await toInput.trigger('change')
    await flushPromises()

    expect(api.get).toHaveBeenLastCalledWith('/reports', {
      params: { from: '2026-01-01', to: '2026-12-31' },
    })
  })

  it('does not refetch on a second visit to an already-loaded Profit tab', async () => {
    loginAsOwner()
    mockProfit()
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    const callsAfterFirstOpen = api.get.mock.calls.filter(([url]) => url === '/reports').length
    expect(callsAfterFirstOpen).toBe(1)

    const payrollTab = wrapper.findAll('button').find((b) => b.text() === 'Payroll')
    await payrollTab.trigger('click')
    await openProfitTab(wrapper)

    expect(api.get.mock.calls.filter(([url]) => url === '/reports').length).toBe(1)
  })

  it('drops the cached profit when an expense changes, so the next visit refetches', async () => {
    loginAsOwner()
    mockEverything()
    vi.mocked(api.post).mockResolvedValue({ data: { data: OWN_EXPENSE } })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)
    expect(callsTo('/reports')).toBe(1)

    await clickTab(wrapper, 'Expenses')
    await wrapper.findAll('button').find((b) => b.text() === 'Add expense').trigger('click')
    await flushPromises()
    const body = new DOMWrapper(document.body)
    await body.find('input[type="number"]').setValue('5000')
    await body.findAll('button').find((b) => b.text() === 'Save').trigger('click')
    await flushPromises()

    await openProfitTab(wrapper)

    // 5,000 of rent has to move the net profit an owner is looking at.
    expect(callsTo('/reports')).toBe(2)
  })

  it('drops the cached profit when an expense is deleted', async () => {
    loginAsOwner()
    mockEverything()
    vi.mocked(api.delete).mockResolvedValue({})
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)
    expect(callsTo('/reports')).toBe(1)

    await clickTab(wrapper, 'Expenses')
    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()

    await openProfitTab(wrapper)

    expect(callsTo('/reports')).toBe(2)
  })

  it('refreshes the expense list and drops the cached profit after a run is finalized', async () => {
    loginAsOwner()
    mockEverything()
    vi.mocked(api.post).mockResolvedValue({ data: { data: FINALIZED_RUN_DETAIL } })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)
    expect(callsTo('/reports')).toBe(1)

    await clickTab(wrapper, 'Payroll')
    const expenseCallsBefore = callsTo('/expenses')
    await wrapper.findAll('button').find((b) => b.text() === 'Finalize').trigger('click')
    await flushPromises()

    // The run just booked a salary expense; the log has to show it.
    expect(callsTo('/expenses')).toBeGreaterThan(expenseCallsBefore)

    await openProfitTab(wrapper)
    expect(callsTo('/reports')).toBe(2)
  })

  it('refreshes the expense list and drops the cached profit after a run is deleted', async () => {
    loginAsOwner()
    mockEverything()
    vi.mocked(api.delete).mockResolvedValue({})
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)
    expect(callsTo('/reports')).toBe(1)

    await clickTab(wrapper, 'Payroll')
    const expenseCallsBefore = callsTo('/expenses')
    await wrapper.findAll('button').find((b) => b.text() === 'Delete').trigger('click')
    await flushPromises()

    expect(callsTo('/expenses')).toBeGreaterThan(expenseCallsBefore)

    await openProfitTab(wrapper)
    expect(callsTo('/reports')).toBe(2)
  })

  it('renders exactly parseApiError(...).message in the banner when the reports call fails', async () => {
    loginAsOwner()
    vi.mocked(api.get)
      .mockReset()
      .mockImplementation((url) => {
        if (url === '/payroll/runs') return Promise.resolve({ data: { data: [] } })
        if (url === '/expenses') return Promise.resolve({ data: { data: [] } })
        if (url === '/reports') {
          return Promise.reject({ response: { status: 500, data: { message: 'Reports service is down' } } })
        }
        return Promise.resolve({ data: { data: null } })
      })
    const wrapper = mountFinanceView()
    await flushPromises()
    await openProfitTab(wrapper)

    expect(wrapper.find('.text-rose-700').text()).toBe('Reports service is down')
  })
})
