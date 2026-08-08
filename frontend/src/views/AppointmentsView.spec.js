import { describe, it, expect } from 'vitest'
import { staffWhoCanDoAll } from './AppointmentsView.vue'

describe('staffWhoCanDoAll', () => {
  const staff = [
    { id: 1, name: 'Alex', services: [{ id: 10 }, { id: 11 }] },
    { id: 2, name: 'Sam', services: [{ id: 10 }] },
    { id: 3, name: 'Unassigned', services: [] },
  ]

  it('keeps only staff who cover every selected service', () => {
    expect(staffWhoCanDoAll(staff, [10, 11]).map((s) => s.name)).toEqual(['Alex'])
  })

  it('returns everyone when nothing is selected', () => {
    expect(staffWhoCanDoAll(staff, [])).toHaveLength(3)
  })
})

/* --------------------------------------------------------------------------
 * The day list renders twice: a table for `md` and up, and a stacked card
 * list below it. Only CSS decides which one a viewer sees, so in jsdom (no
 * media queries) both are in the DOM at once and every assertion below is
 * scoped to the `md:hidden` card container. The point of these cases is that
 * the phone branch is not a reduced copy: same fields, same controls, same
 * handlers with the same arguments as the row a desktop user gets.
 * -------------------------------------------------------------------------- */

import { vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  }
})

// The view reads ?date off the route to decide which day to open on.
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }) }))

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import AppointmentsView from '@/views/AppointmentsView.vue'

// Stubbed so the assertions are about this view, not about Modal's Teleport
// or PaymentModal's own fetching. Each keeps the props it is handed, which is
// what proves the right appointment reached it.
const stubs = {
  Modal: {
    props: ['title'],
    template: '<div class="stub-modal"><slot /><slot name="footer" /></div>',
  },
  ConfirmDialog: {
    props: ['title', 'message', 'loading'],
    template: '<div class="stub-confirm">{{ message }}</div>',
  },
  PaymentModal: {
    props: ['appointment'],
    template: '<div class="stub-payment">invoice:{{ appointment.id }}</div>',
  },
}

const APPOINTMENT = {
  id: 7,
  booking_date: '2026-08-08',
  start_time: '10:00',
  end_time: '10:45',
  status: 'confirmed',
  price: '25.00',
  notes: 'Allergic to ammonia',
  customer: { id: 3, name: 'Ruma Akter', phone: '01711000111' },
  staff: { id: 5, name: 'Alex' },
  branch: { id: 1, name: 'Gulshan' },
  services: [
    { id: 10, name: 'Hair cut' },
    { id: 11, name: 'Blow dry' },
  ],
}

// Same formatter the view uses, so the expectation does not hard-code a
// locale's idea of where the currency symbol goes.
const USD = (amount) =>
  new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number(amount))

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, subscription_plan: 'free', currency: 'USD' },
  })
}

let currentWrapper = null
async function mountDayList(role, appointments = [APPOINTMENT]) {
  loginAs(role)
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === '/appointments') return Promise.resolve({ data: { data: appointments } })
    return Promise.resolve({ data: { data: [] } })
  })
  currentWrapper = mount(AppointmentsView, { global: { stubs } })
  await flushPromises()
  return currentWrapper
}

// The stacked branch, i.e. what a phone actually shows.
const phoneList = (wrapper) => wrapper.find('div.md\\:hidden')
// The table branch, kept around to compare the two against each other.
const tableList = (wrapper) => wrapper.find('div.md\\:block')

const buttonsIn = (scope) => scope.findAll('button').map((b) => b.text())

describe('AppointmentsView — the phone (md:hidden) day list', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.patch).mockReset().mockResolvedValue({ data: { data: {} } })
    vi.mocked(api.delete).mockReset().mockResolvedValue({ data: {} })
  })

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
  })

  it('renders both branches for the same day, and the card carries every field the table row does', async () => {
    const wrapper = await mountDayList('owner')

    expect(tableList(wrapper).exists()).toBe(true)
    expect(phoneList(wrapper).exists()).toBe(true)

    const text = phoneList(wrapper).text()
    expect(text).toContain('10:00')
    expect(text).toContain('10:45')
    expect(text).toContain('Confirmed')
    expect(text).toContain('Ruma Akter')
    expect(text).toContain('01711000111')
    expect(text).toContain('Hair cut, Blow dry')
    expect(text).toContain('Alex')
    expect(text).toContain('Gulshan')
    expect(text).toContain(USD('25.00'))
    // Notes are the field most easily lost when a row is restacked.
    expect(text).toContain('Allergic to ammonia')

    // The status pill keeps its own semantic hue rather than the accent.
    expect(phoneList(wrapper).find('.sh-badge').classes()).toContain('sh-badge-confirmed')
  })

  it('offers an owner exactly the controls the table row offers', async () => {
    const wrapper = await mountDayList('owner')

    // Same set, in the same order, as the desktop row — including the three
    // quick-status actions left after the current status is excluded.
    const expected = ['Confirmed', 'Completed', 'Cancelled', 'No-show', 'Invoice', 'Edit', 'Delete']
    expect(buttonsIn(phoneList(wrapper))).toEqual(expected)
    expect(buttonsIn(tableList(wrapper))).toEqual(expected)
  })

  it('hides only the appointment’s current status among the quick actions', async () => {
    const wrapper = await mountDayList('owner')

    const quick = phoneList(wrapper)
      .findAll('button')
      .filter((b) => ['Confirmed', 'Completed', 'Cancelled', 'No-show'].includes(b.text()))
    const hidden = quick.filter((b) => b.element.style.display === 'none').map((b) => b.text())

    // v-show, so the button is present but not displayed.
    expect(hidden).toEqual(['Confirmed'])
  })

  it('sends the same PATCH from the card as from the row, and reloads the day', async () => {
    const wrapper = await mountDayList('owner')
    const getCallsBefore = vi.mocked(api.get).mock.calls.length

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Completed')
      .trigger('click')
    await flushPromises()

    expect(api.patch).toHaveBeenCalledTimes(1)
    expect(api.patch).toHaveBeenCalledWith('/appointments/7', { status: 'completed' })
    expect(vi.mocked(api.get).mock.calls.length).toBeGreaterThan(getCallsBefore)
  })

  it('opens the invoice for the appointment the card belongs to', async () => {
    const wrapper = await mountDayList('owner')

    expect(wrapper.find('.stub-payment').exists()).toBe(false)

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Invoice')
      .trigger('click')

    expect(wrapper.find('.stub-payment').text()).toBe('invoice:7')
  })

  it('opens the edit form, not the create form, from the card', async () => {
    const wrapper = await mountDayList('owner')

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Edit')
      .trigger('click')
    await flushPromises()

    const modal = wrapper.find('.stub-modal')
    expect(modal.exists()).toBe(true)
    // "Save changes" rather than "Create appointment" is the only rendered
    // evidence that openEdit() received the appointment.
    expect(modal.text()).toContain('Save changes')
    // Prefilled from that appointment, so the edit targets id 7's data.
    expect(modal.find('input[type="time"]').element.value).toBe('10:00')
  })

  it('confirms a delete against the same appointment', async () => {
    const wrapper = await mountDayList('owner')

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Delete')
      .trigger('click')

    expect(wrapper.find('.stub-confirm').text()).toContain('Ruma Akter')
    expect(wrapper.find('.stub-confirm').text()).toContain('10:00')
  })

  it('withholds Edit and Delete from a staff member in the card branch too', async () => {
    const wrapper = await mountDayList('staff')

    const labels = buttonsIn(phoneList(wrapper))
    expect(labels).not.toContain('Edit')
    expect(labels).not.toContain('Delete')
    // Read-only actions survive: the schedule is still workable.
    expect(labels).toContain('Invoice')
    expect(labels).toContain('Completed')
  })

  it('shows one card per appointment', async () => {
    const second = { ...APPOINTMENT, id: 8, start_time: '11:30', customer: { id: 4, name: 'Nadia' } }
    const wrapper = await mountDayList('owner', [APPOINTMENT, second])

    expect(phoneList(wrapper).findAll('.sh-card')).toHaveLength(2)
    expect(phoneList(wrapper).text()).toContain('Nadia')
  })
})
