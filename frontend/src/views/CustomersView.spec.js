/* --------------------------------------------------------------------------
 * The customer book renders twice: a table for `md` and up, and a stacked
 * card list below it. Only CSS decides which one a viewer sees, so in jsdom
 * (no media queries) both are in the DOM at once and every assertion below is
 * scoped to the `md:hidden` card container. The point of these cases is that
 * the phone branch is not a reduced copy: same fields, same controls, same
 * handlers with the same arguments as the row a desktop user gets.
 * -------------------------------------------------------------------------- */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import CustomersView from '@/views/CustomersView.vue'

// Stubbed so the assertions are about this view, not about Modal's Teleport.
// Each keeps the props it is handed, which is what proves the right customer
// reached it.
const stubs = {
  Modal: {
    props: ['title'],
    template: '<div class="stub-modal"><slot /><slot name="footer" /></div>',
  },
  ConfirmDialog: {
    props: ['title', 'message', 'loading'],
    template: '<div class="stub-confirm">{{ message }}</div>',
  },
}

const CUSTOMER = {
  id: 4,
  name: 'Ruma Akter',
  phone: '01711000111',
  email: 'ruma@example.com',
  notes: 'Prefers the morning slot',
}

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, subscription_plan: 'free' },
  })
}

let currentWrapper = null
async function mountList(role, customers = [CUSTOMER]) {
  loginAs(role)
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === '/customers') return Promise.resolve({ data: { data: customers } })
    return Promise.resolve({ data: { data: [] } })
  })
  currentWrapper = mount(CustomersView, { global: { stubs } })
  await flushPromises()
  return currentWrapper
}

// The stacked branch, i.e. what a phone actually shows.
const phoneList = (wrapper) => wrapper.find('div.md\\:hidden')
// The table branch, kept around to compare the two against each other.
const tableList = (wrapper) => wrapper.find('div.md\\:block')

const buttonsIn = (scope) => scope.findAll('button').map((b) => b.text())

describe('CustomersView — the phone (md:hidden) customer list', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.put).mockReset().mockResolvedValue({ data: { data: {} } })
    vi.mocked(api.delete).mockReset().mockResolvedValue({ data: {} })
  })

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
  })

  it('renders both branches, and the card carries every field the table row does', async () => {
    const wrapper = await mountList('owner')

    expect(tableList(wrapper).exists()).toBe(true)
    expect(phoneList(wrapper).exists()).toBe(true)

    const text = phoneList(wrapper).text()
    expect(text).toContain('Ruma Akter')
    expect(text).toContain('01711000111')
    expect(text).toContain('ruma@example.com')
    // Notes are the field most easily lost when a row is restacked.
    expect(text).toContain('Prefers the morning slot')
  })

  it('falls back to the same em dash as the table when a field is empty', async () => {
    const wrapper = await mountList('owner', [{ id: 5, name: 'Nadia', phone: '', email: null, notes: null }])

    // Three blank fields, three dashes — the card never silently drops a row.
    expect(phoneList(wrapper).text().match(/—/g)).toHaveLength(3)
  })

  it('offers an owner exactly the controls the table row offers', async () => {
    const wrapper = await mountList('owner')

    const expected = ['Edit', 'Delete']
    expect(buttonsIn(phoneList(wrapper))).toEqual(expected)
    expect(buttonsIn(tableList(wrapper))).toEqual(expected)
  })

  it('opens the edit form, not the create form, from the card', async () => {
    const wrapper = await mountList('owner')

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Edit')
      .trigger('click')
    await flushPromises()

    const modal = wrapper.find('.stub-modal')
    expect(modal.exists()).toBe(true)
    // "Save changes" rather than "Create customer" is the only rendered
    // evidence that openEdit() received the customer.
    expect(modal.text()).toContain('Save changes')
    // Prefilled from that customer, so the edit targets id 4's data.
    expect(modal.find('input[type="text"]').element.value).toBe('Ruma Akter')
  })

  it('confirms a delete against the same customer', async () => {
    const wrapper = await mountList('owner')

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Delete')
      .trigger('click')

    expect(wrapper.find('.stub-confirm').text()).toContain('Ruma Akter')
  })

  it('withholds Edit and Delete from a staff member in the card branch too', async () => {
    const wrapper = await mountList('staff')

    expect(buttonsIn(phoneList(wrapper))).toEqual([])
    expect(buttonsIn(tableList(wrapper))).toEqual([])
    // The book itself stays readable.
    expect(phoneList(wrapper).text()).toContain('Ruma Akter')
  })

  it('shows one card per customer', async () => {
    const second = { ...CUSTOMER, id: 5, name: 'Nadia' }
    const wrapper = await mountList('owner', [CUSTOMER, second])

    expect(phoneList(wrapper).findAll('.sh-card')).toHaveLength(2)
    expect(phoneList(wrapper).text()).toContain('Nadia')
  })
})
