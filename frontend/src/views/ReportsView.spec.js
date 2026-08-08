/* --------------------------------------------------------------------------
 * The staff performance table renders twice: a table for `md` and up, and a
 * stacked card list below it. Only CSS decides which one a viewer sees, so in
 * jsdom (no media queries) both are in the DOM at once and every assertion
 * below is scoped to the `md:hidden` card container or the `md:block` table
 * one. The point of these cases is that the phone branch is not a reduced
 * copy: same fields, same guards, same rows in the same order as the table a
 * desktop user gets.
 *
 * Every fixture carries two staff rows and the assertions target the *second*,
 * so a card wired to staff[0] cannot pass by accident.
 * -------------------------------------------------------------------------- */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn() },
  }
})

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import ReportsView from '@/views/ReportsView.vue'

function loginAsOwner() {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Owner', role: 'owner' },
    organization: { id: 9, subscription_plan: 'free', currency: 'USD' },
  })
}

// Two staff, the second one deliberately unlike the first in every field.
const STAFF = [
  {
    staff_id: 1,
    name: 'Ruma',
    bookings: 4,
    earned: '1100.00',
    rating: { average: 4.8, count: 5 },
  },
  {
    staff_id: 2,
    name: 'Nadia Chowdhury',
    bookings: 7,
    earned: '1925.50',
    rating: { average: 4.2, count: 3 },
  },
]

function report({ staff = STAFF } = {}) {
  return {
    summary: {
      earned: '3025.50',
      bookings: 11,
      avg_ticket: '275.05',
      delta: { earned_pct: 12, bookings_pct: -4 },
    },
    revenue: { granularity: 'day', points: [] },
    top_services: [],
    staff,
    bookings: { by_status: { completed: 11 }, busiest_day: null, busiest_hour: null },
  }
}

let currentWrapper = null

function mountWith(payload = report()) {
  vi.mocked(api.get)
    .mockReset()
    .mockResolvedValue({ data: { data: payload } })
  currentWrapper = mount(ReportsView)
  return currentWrapper
}

// The stacked branch, i.e. what a phone actually shows.
const phoneList = (wrapper) => wrapper.find('div.md\\:hidden')
// The table branch, kept around to compare the two against each other.
const tableList = (wrapper) => wrapper.find('div.md\\:block')

const staffCards = (wrapper) => phoneList(wrapper).findAll('.sh-card')

describe('ReportsView — the phone (md:hidden) staff performance list', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
    vi.restoreAllMocks()
  })

  it('renders both branches, and the card carries every column the table row does', async () => {
    loginAsOwner()
    const wrapper = mountWith()
    await flushPromises()

    expect(tableList(wrapper).exists()).toBe(true)
    expect(phoneList(wrapper).exists()).toBe(true)

    // The second staff member's card, so a card bound to staff[0] fails here.
    const card = staffCards(wrapper)[1]
    const text = card.text()
    expect(text).toContain('Nadia Chowdhury')
    expect(text).toContain('Bookings')
    expect(text).toContain('7')
    expect(text).toContain('Earned')
    // Formatted as currency, not the bare API string "1925.50".
    expect(text).toContain('$1,925.50')
    expect(text).toContain('Rating')
    expect(text).toContain('★ 4.2')
    expect(text).toContain('(3)')

    // Nothing the desktop row shows is missing from the card.
    const row = tableList(wrapper).findAll('tbody tr')[1]
    expect(row.text()).toContain('Nadia Chowdhury')
    expect(row.text()).toContain('$1,925.50')
    expect(row.text()).toContain('★ 4.2')
  })

  it('shows one card per staff row, in the same order as the table', async () => {
    loginAsOwner()
    const wrapper = mountWith()
    await flushPromises()

    const cardNames = staffCards(wrapper).map((c) => c.find('p').text())
    const rowNames = tableList(wrapper).findAll('tbody tr').map((r) => r.findAll('td')[0].text())

    expect(cardNames).toEqual(['Ruma', 'Nadia Chowdhury'])
    expect(cardNames).toEqual(rowNames)
  })

  it('keeps the unrated fallback on the card the unrated staff member owns', async () => {
    loginAsOwner()
    const wrapper = mountWith(
      report({
        staff: [STAFF[0], { ...STAFF[1], rating: { average: null, count: 0 } }],
      }),
    )
    await flushPromises()

    // Same v-if guard as the table cell: the em dash, and no star, on the
    // second card only.
    const card = staffCards(wrapper)[1]
    expect(card.text()).toContain('—')
    expect(card.text()).not.toContain('★')
    // The first card still shows its rating, so the guard is per row.
    expect(staffCards(wrapper)[0].text()).toContain('★ 4.8')

    const row = tableList(wrapper).findAll('tbody tr')[1]
    expect(row.text()).toContain('—')
    expect(row.text()).not.toContain('★')
  })

  it('drops both branches for the same empty message when nobody completed a booking', async () => {
    loginAsOwner()
    const wrapper = mountWith(report({ staff: [] }))
    await flushPromises()

    expect(phoneList(wrapper).exists()).toBe(false)
    expect(tableList(wrapper).exists()).toBe(false)
    expect(wrapper.text()).toContain('No completed bookings in this range.')
  })
})
