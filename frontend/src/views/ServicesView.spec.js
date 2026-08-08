/* --------------------------------------------------------------------------
 * The catalogue renders twice: a table for `md` and up, and a stacked card
 * list below it. Only CSS decides which one a viewer sees, so in jsdom (no
 * media queries) both are in the DOM at once and every assertion below is
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
import ServicesView from '@/views/ServicesView.vue'

// Stubbed so the assertions are about this view, not about Modal's Teleport.
// Each keeps the props it is handed, which is what proves the right service
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

const SERVICE = {
  id: 10,
  name: 'Hair cut',
  category: { id: 2, name: 'Hair' },
  duration: 30,
  price: '25.00',
  status: 'active',
  description: 'Wash and cut',
}

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, subscription_plan: 'free' },
  })
}

let currentWrapper = null
async function mountList(role, services = [SERVICE]) {
  loginAs(role)
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === '/services') return Promise.resolve({ data: { data: services } })
    return Promise.resolve({ data: { data: [] } })
  })
  currentWrapper = mount(ServicesView, { global: { stubs } })
  await flushPromises()
  return currentWrapper
}

// The stacked branch, i.e. what a phone actually shows.
const phoneList = (wrapper) => wrapper.find('div.md\\:hidden')
// The table branch, kept around to compare the two against each other.
const tableList = (wrapper) => wrapper.find('div.md\\:block')

const buttonsIn = (scope) => scope.findAll('button').map((b) => b.text())

describe('ServicesView — the phone (md:hidden) catalogue list', () => {
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
    expect(text).toContain('Hair cut')
    expect(text).toContain('Hair')
    expect(text).toContain('30 min')
    // formatPrice() fixes the decimals; the card must show the same string.
    expect(text).toContain('25.00')
    expect(text).toContain('active')
  })

  it('paints the status pill with the same fixed hue in both branches, never the accent', async () => {
    const wrapper = await mountList('owner', [
      { ...SERVICE },
      { ...SERVICE, id: 11, name: 'Blow dry', status: 'inactive' },
    ])

    const cardBadges = phoneList(wrapper).findAll('.sh-badge')
    const rowBadges = tableList(wrapper).findAll('.sh-badge')
    expect(cardBadges.map((b) => b.classes().join(' '))).toEqual(
      rowBadges.map((b) => b.classes().join(' ')),
    )
    expect(cardBadges[0].classes()).toContain('bg-emerald-100')
    expect(cardBadges[1].classes()).toContain('bg-ink/10')
  })

  it('falls back to the same em dash as the table when a service has no category', async () => {
    const wrapper = await mountList('owner', [{ ...SERVICE, category: null }])

    expect(phoneList(wrapper).text()).toContain('—')
    expect(tableList(wrapper).text()).toContain('—')
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
    // "Save changes" rather than "Create service" is the only rendered
    // evidence that openEdit() received the service.
    expect(modal.text()).toContain('Save changes')
    // Prefilled from that service, so the edit targets id 10's data.
    expect(modal.find('input[type="text"]').element.value).toBe('Hair cut')
  })

  it('confirms a delete against the same service', async () => {
    const wrapper = await mountList('owner')

    await phoneList(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Delete')
      .trigger('click')

    expect(wrapper.find('.stub-confirm').text()).toContain('Hair cut')
  })

  it('withholds Edit and Delete from a staff member in the card branch too', async () => {
    const wrapper = await mountList('staff')

    expect(buttonsIn(phoneList(wrapper))).toEqual([])
    expect(buttonsIn(tableList(wrapper))).toEqual([])
    // The catalogue itself stays readable.
    expect(phoneList(wrapper).text()).toContain('Hair cut')
  })

  it('shows one card per service', async () => {
    const second = { ...SERVICE, id: 11, name: 'Blow dry' }
    const wrapper = await mountList('owner', [SERVICE, second])

    expect(phoneList(wrapper).findAll('.sh-card')).toHaveLength(2)
    expect(phoneList(wrapper).text()).toContain('Blow dry')
  })
})
