/* --------------------------------------------------------------------------
 * Payroll lines and the expense log each render twice: a table for `md` and
 * up, and a stacked card list below it. Only CSS decides which one a viewer
 * sees, so in jsdom (no media queries) both are in the DOM at once and every
 * assertion below is scoped to the `md:hidden` card container or the
 * `md:block` table one. The point of these cases is that the phone branch is
 * not a reduced copy: same fields, same controls, same handlers with the same
 * arguments as the row a desktop user gets.
 *
 * FinanceView.spec.js stays the authority on behaviour; this file only covers
 * the second branch it does not reach.
 * -------------------------------------------------------------------------- */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, DOMWrapper } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

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

const DRAFT_RUN_SUMMARY = {
  id: 5,
  period_month: '2026-08-01',
  period_label: 'August 2026',
  status: 'draft',
  total_amount: '290.00',
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
      tips_amount: '15.00',
      total_amount: '290.00',
    },
  ],
}

const FINALIZED_RUN_DETAIL = {
  ...DRAFT_RUN_DETAIL,
  status: 'finalized',
  finalized_at: '2026-08-09T10:00:00+00:00',
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

// A second manual row, so the per-card handlers have to pick the right one
// rather than always reaching for expenses[0].
const OTHER_EXPENSE = {
  ...OWN_EXPENSE,
  id: 23,
  category: 'supplies',
  expense_date: '2026-08-07',
  amount: '40.00',
  note: 'Shampoo',
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

let currentWrapper = null

function mountWith({ runs = [], detail = null, expenses = [] } = {}) {
  vi.mocked(api.get)
    .mockReset()
    .mockImplementation((url) => {
      if (url === '/payroll/runs') return Promise.resolve({ data: { data: runs } })
      if (detail && url === `/payroll/runs/${detail.id}`) {
        return Promise.resolve({ data: { data: detail } })
      }
      if (url === '/expenses') return Promise.resolve({ data: { data: expenses } })
      return Promise.resolve({ data: { data: null } })
    })
  currentWrapper = mount(FinanceView)
  return currentWrapper
}

// The stacked branch, i.e. what a phone actually shows.
const phoneList = (wrapper) => wrapper.find('div.md\\:hidden')
// The table branch, kept around to compare the two against each other.
const tableList = (wrapper) => wrapper.find('div.md\\:block')

const buttonsIn = (scope) => scope.findAll('button').map((b) => b.text())

async function openExpensesTab(wrapper) {
  await wrapper.findAll('button').find((b) => b.text() === 'Expenses').trigger('click')
}

function resetMocks() {
  setActivePinia(createPinia())
  vi.mocked(api.post).mockReset()
  vi.mocked(api.patch).mockReset()
  vi.mocked(api.delete).mockReset()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
}

describe('FinanceView — the phone (md:hidden) payroll lines', () => {
  beforeEach(resetMocks)

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
    vi.restoreAllMocks()
  })

  it('renders both branches, and the card carries every column the table row does', async () => {
    loginAsOwner()
    const wrapper = mountWith({ runs: [DRAFT_RUN_SUMMARY], detail: DRAFT_RUN_DETAIL })
    await flushPromises()

    expect(tableList(wrapper).exists()).toBe(true)
    expect(phoneList(wrapper).exists()).toBe(true)

    const text = phoneList(wrapper).text()
    expect(text).toContain('Ruma')
    // Rule + rate, exactly as the "Rule" column renders them.
    expect(text).toContain('Commission only')
    expect(text).toContain('25.00%')
    expect(text).toContain('Bookings')
    expect(text).toContain('4')
    // Earned, tips and total, formatted as currency rather than raw decimals.
    expect(text).toContain('$1,100.00')
    expect(text).toContain('$15.00')
    expect(text).toContain('$290.00')
    // A tip stays in its own field on a phone too, never folded into commission.
    expect(text).toContain('Tips')
    expect(text).toContain('Commission')
  })

  it('offers the same two editable amounts on a draft run as the table row', async () => {
    loginAsOwner()
    const wrapper = mountWith({ runs: [DRAFT_RUN_SUMMARY], detail: DRAFT_RUN_DETAIL })
    await flushPromises()

    expect(tableList(wrapper).findAll('input[type="number"]')).toHaveLength(2)
    expect(phoneList(wrapper).findAll('input[type="number"]')).toHaveLength(2)
  })

  it('sends the same PATCH from the card as from the row, for the field that was edited', async () => {
    loginAsOwner()
    vi.mocked(api.patch).mockResolvedValue({ data: { data: {} } })
    const wrapper = mountWith({
      runs: [DRAFT_RUN_SUMMARY],
      detail: {
        ...DRAFT_RUN_DETAIL,
        lines: [
          DRAFT_RUN_DETAIL.lines[0],
          { ...DRAFT_RUN_DETAIL.lines[0], id: 12, staff_name: 'Nadia' },
        ],
      },
    })
    await flushPromises()

    // The second card's second input: Nadia's Commission. Both indices matter —
    // a card wired to lines[0], or to the salary field above, fails here.
    const inputs = phoneList(wrapper).findAll('input[type="number"]')
    expect(inputs).toHaveLength(4)
    await inputs[3].setValue('300')
    await inputs[3].trigger('change')
    await flushPromises()

    expect(api.patch).toHaveBeenCalledWith('/payroll/runs/5/lines/12', { commission_amount: 300 })
  })

  it('locks the card down once the run is finalized, showing amounts as text', async () => {
    loginAsOwner()
    const wrapper = mountWith({
      runs: [{ ...DRAFT_RUN_SUMMARY, status: 'finalized' }],
      detail: FINALIZED_RUN_DETAIL,
    })
    await flushPromises()

    expect(phoneList(wrapper).findAll('input')).toHaveLength(0)
    expect(phoneList(wrapper).text()).toContain('$275.00')
  })

  it('shows the same "no pay rule" message in both branches', async () => {
    loginAsOwner()
    const wrapper = mountWith({
      runs: [DRAFT_RUN_SUMMARY],
      detail: { ...DRAFT_RUN_DETAIL, lines: [] },
    })
    await flushPromises()

    expect(phoneList(wrapper).text()).toContain('No staff have a pay rule yet.')
    expect(tableList(wrapper).text()).toContain('No staff have a pay rule yet.')
  })

  it('shows one card per payroll line', async () => {
    loginAsOwner()
    const wrapper = mountWith({
      runs: [DRAFT_RUN_SUMMARY],
      detail: {
        ...DRAFT_RUN_DETAIL,
        lines: [
          DRAFT_RUN_DETAIL.lines[0],
          { ...DRAFT_RUN_DETAIL.lines[0], id: 12, staff_name: 'Nadia' },
        ],
      },
    })
    await flushPromises()

    expect(phoneList(wrapper).findAll('.sh-card')).toHaveLength(2)
    expect(phoneList(wrapper).text()).toContain('Nadia')
  })
})

describe('FinanceView — the phone (md:hidden) expense log', () => {
  beforeEach(resetMocks)

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
    vi.restoreAllMocks()
  })

  it('renders both branches, and the card carries every column the table row does', async () => {
    loginAsOwner()
    const wrapper = mountWith({ expenses: [OWN_EXPENSE] })
    await flushPromises()
    await openExpensesTab(wrapper)

    expect(tableList(wrapper).exists()).toBe(true)

    const text = phoneList(wrapper).text()
    expect(text).toContain('2026-08-05')
    expect(text).toContain('rent')
    expect(text).toContain('August rent')
    expect(text).toContain('$1,050.00')
  })

  it('falls back to the same em dash as the table when an expense has no note', async () => {
    loginAsOwner()
    const wrapper = mountWith({ expenses: [{ ...OWN_EXPENSE, note: null }] })
    await flushPromises()
    await openExpensesTab(wrapper)

    expect(phoneList(wrapper).text()).toContain('—')
    expect(tableList(wrapper).text()).toContain('—')
  })

  it('offers exactly the controls the table row offers, including withholding Edit/Delete from a locked row', async () => {
    loginAsOwner()
    const wrapper = mountWith({ expenses: [OWN_EXPENSE, PAYROLL_EXPENSE] })
    await flushPromises()
    await openExpensesTab(wrapper)

    // The manual row keeps both controls; the payroll-owned one offers only
    // the link back to its run, on a phone as on a desktop.
    const expected = ['Edit', 'Delete', 'From payroll']
    expect(buttonsIn(phoneList(wrapper))).toEqual(expected)
    expect(buttonsIn(tableList(wrapper))).toEqual(expected)
  })

  it('opens the edit form for the expense the card belongs to, prefilled', async () => {
    loginAsOwner()
    vi.mocked(api.patch).mockResolvedValue({ data: { data: OTHER_EXPENSE } })
    const wrapper = mountWith({ expenses: [OWN_EXPENSE, OTHER_EXPENSE] })
    await flushPromises()
    await openExpensesTab(wrapper)

    // The *second* card, so a handler wired to expenses[0] cannot pass.
    const edits = phoneList(wrapper).findAll('button').filter((b) => b.text() === 'Edit')
    expect(edits).toHaveLength(2)
    await edits[1].trigger('click')
    await flushPromises()

    const body = new DOMWrapper(document.body)
    expect(body.find('h2').text()).toBe('Edit expense')
    expect(body.find('input[type="number"]').element.value).toBe('40.00')
  })

  it('deletes the expense the card belongs to, after confirmation', async () => {
    loginAsOwner()
    vi.mocked(api.delete).mockResolvedValue({})
    const wrapper = mountWith({ expenses: [OWN_EXPENSE, OTHER_EXPENSE] })
    await flushPromises()
    await openExpensesTab(wrapper)

    // Again the second card: the id in the request is the only thing that
    // proves the card is bound to its own row.
    const deletes = phoneList(wrapper).findAll('button').filter((b) => b.text() === 'Delete')
    expect(deletes).toHaveLength(2)
    await deletes[1].trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(api.delete).toHaveBeenCalledWith('/expenses/23')
  })

  it('links a locked card to its payroll run, switching to the Payroll tab and opening that run', async () => {
    loginAsOwner()
    const wrapper = mountWith({
      runs: [],
      detail: FINALIZED_RUN_DETAIL,
      expenses: [OWN_EXPENSE, PAYROLL_EXPENSE],
    })
    await flushPromises()
    await openExpensesTab(wrapper)

    const link = phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text().includes('From payroll'))
    await link.trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/payroll/runs/5')
    expect(wrapper.text()).toContain('Open payroll')
    expect(wrapper.text()).toContain('Ruma')
  })

  it('carries the same running total as the table foot', async () => {
    loginAsOwner()
    const wrapper = mountWith({ expenses: [OWN_EXPENSE, PAYROLL_EXPENSE] })
    await flushPromises()
    await openExpensesTab(wrapper)

    expect(phoneList(wrapper).text()).toContain('Total')
    expect(phoneList(wrapper).text()).toContain('$3,050.00')
    expect(tableList(wrapper).text()).toContain('$3,050.00')
  })

  it('shows one card per expense, plus the total card', async () => {
    loginAsOwner()
    const wrapper = mountWith({ expenses: [OWN_EXPENSE, PAYROLL_EXPENSE] })
    await flushPromises()
    await openExpensesTab(wrapper)

    // Two expenses and the total row that closes the list.
    expect(phoneList(wrapper).findAll('.sh-card')).toHaveLength(3)
    expect(phoneList(wrapper).text()).toContain('salary')
  })
})
