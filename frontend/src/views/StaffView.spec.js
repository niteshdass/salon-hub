import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, DOMWrapper } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls; keep everything else (including TOKEN_KEY) real
// so nothing here drifts from the actual '@/lib/api' module.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import StaffView from '@/views/StaffView.vue'

// The create/edit form renders inside <Modal>, which <Teleport>s its whole
// markup to document.body — outside the mounted component's own element
// tree. `wrapper.find*` only searches inside that tree, so every assertion
// or interaction that targets the modal goes through a wrapper scoped to
// document.body instead (confirmed empirically: wrapper.html() never
// contains "Compensation", document.body.innerHTML always does).
const body = () => new DOMWrapper(document.body)

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, subscription_plan: 'free' },
  })
}

// The values a request for this member returns to an owner: pay fields
// present, money as the decimal-cast strings the backend actually sends
// ("1000.00", "25.00"), not numbers.
const HYBRID_MEMBER = {
  id: 1,
  name: 'Ruma',
  email: 'ruma@example.com',
  phone: '',
  designation: 'Stylist',
  bio: '',
  pay_type: 'hybrid',
  monthly_salary: '1000.00',
  commission_rate: '25.00',
  services: [],
  working_days_json: null,
  working_hours_json: null,
}

// What the same member looks like to a manager: StaffResource omits the
// three pay keys outright for a non-owner — they are absent, not null.
const MEMBER_WITHOUT_PAY_FIELDS = {
  id: 1,
  name: 'Ruma',
  email: 'ruma@example.com',
  phone: '',
  designation: 'Stylist',
  bio: '',
  services: [],
  working_days_json: null,
  working_hours_json: null,
}

function mockStaffList(member) {
  vi.mocked(api.get)
    .mockReset()
    .mockImplementation((url) => {
      if (url === '/staff') return Promise.resolve({ data: { data: [member] } })
      if (url === '/services') return Promise.resolve({ data: { data: [] } })
      return Promise.resolve({ data: { data: [] } })
    })
}

async function openEditForm(wrapper) {
  const editButton = wrapper.findAll('button').find((b) => b.text() === 'Edit')
  await editButton.trigger('click')
  await flushPromises()
}

async function clickSave() {
  const saveButton = body()
    .findAll('button')
    .find((b) => b.text().includes('Save changes'))
  await saveButton.trigger('click')
  await flushPromises()
}

// Modal content is teleported straight to the real document.body, which
// jsdom does not reset between tests in the same file. Left alone, a second
// test's `body().find(...)` can match the previous test's still-mounted
// modal instead of its own — every scoped-to-body query below depends on
// this cleanup running first.
let currentWrapper = null
function mountStaffView() {
  currentWrapper = mount(StaffView)
  return currentWrapper
}

describe('StaffView — Compensation section', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.put).mockReset().mockResolvedValue({ data: { data: {} } })
  })

  afterEach(() => {
    currentWrapper?.unmount()
    currentWrapper = null
  })

  it('shows an owner the Compensation section, pre-filled from the API\'s string money values', async () => {
    loginAs('owner')
    mockStaffList(HYBRID_MEMBER)
    const wrapper = mountStaffView()
    await flushPromises()
    await openEditForm(wrapper)

    expect(body().text()).toContain('Compensation')
    // All four pay rules are offered, and the member's own rule is checked.
    const radios = body().findAll('input[type="radio"]')
    expect(radios).toHaveLength(4)
    expect(radios.find((r) => r.element.value === 'hybrid').element.checked).toBe(true)

    // The read direction: the raw API strings land in the inputs verbatim,
    // not coerced to numbers (which would drop the trailing zeros).
    const numberInputs = body().findAll('input[type="number"]')
    expect(numberInputs.map((i) => i.element.value)).toEqual(['1000.00', '25.00'])
  })

  it('hides the Compensation section from a manager, and the form survives the pay fields being absent from the record entirely', async () => {
    loginAs('manager')
    mockStaffList(MEMBER_WITHOUT_PAY_FIELDS)
    const wrapper = mountStaffView()
    await flushPromises()

    // Would throw before the form even opens if reading member.pay_type /
    // monthly_salary / commission_rate off an object where those keys were
    // simply never set broke anything.
    await openEditForm(wrapper)

    expect(body().text()).not.toContain('Compensation')
    expect(body().findAll('input[type="radio"]')).toHaveLength(0)

    // Saving must still work, and must not send any of the three pay keys.
    await clickSave()
    expect(api.put).toHaveBeenCalledTimes(1)
    const [, payload] = vi.mocked(api.put).mock.calls[0]
    expect(payload).not.toHaveProperty('pay_type')
    expect(payload).not.toHaveProperty('monthly_salary')
    expect(payload).not.toHaveProperty('commission_rate')
  })

  it('reveals exactly the right money field for each pay type', async () => {
    loginAs('owner')
    mockStaffList(HYBRID_MEMBER)
    const wrapper = mountStaffView()
    await flushPromises()
    await openEditForm(wrapper)

    const setPayType = async (value) => {
      await body().find(`input[value="${value}"]`).setValue()
      await flushPromises()
    }

    await setPayType('none')
    expect(body().text()).not.toContain('Monthly salary')
    expect(body().text()).not.toContain('Commission rate')
    expect(body().findAll('input[type="number"]')).toHaveLength(0)

    await setPayType('commission')
    expect(body().text()).not.toContain('Monthly salary')
    expect(body().text()).toContain('Commission rate')
    expect(body().findAll('input[type="number"]')).toHaveLength(1)

    await setPayType('salary')
    expect(body().text()).toContain('Monthly salary')
    expect(body().text()).not.toContain('Commission rate')
    expect(body().findAll('input[type="number"]')).toHaveLength(1)

    await setPayType('hybrid')
    expect(body().text()).toContain('Monthly salary')
    expect(body().text()).toContain('Commission rate')
    expect(body().findAll('input[type="number"]')).toHaveLength(2)
  })

  it('submits pay_type/monthly_salary/commission_rate as numbers, converted from the API\'s decimal strings', async () => {
    loginAs('owner')
    mockStaffList(HYBRID_MEMBER)
    const wrapper = mountStaffView()
    await flushPromises()
    await openEditForm(wrapper)

    await clickSave()

    expect(api.put).toHaveBeenCalledTimes(1)
    const [url, payload] = vi.mocked(api.put).mock.calls[0]
    expect(url).toBe('/staff/1')
    expect(payload.pay_type).toBe('hybrid')
    expect(payload.monthly_salary).toBe(1000)
    expect(payload.commission_rate).toBe(25)
    expect(typeof payload.monthly_salary).toBe('number')
    expect(typeof payload.commission_rate).toBe('number')
  })

  it('does not leak a stale rate into the payload after switching pay types before saving', async () => {
    loginAs('owner')
    // Starts at 'none' so there is no pre-existing rate to reset — the test
    // proves the switch clears what was just typed, not what the API sent.
    mockStaffList({ ...HYBRID_MEMBER, pay_type: 'none', monthly_salary: null, commission_rate: null })
    const wrapper = mountStaffView()
    await flushPromises()
    await openEditForm(wrapper)

    await body().find('input[value="commission"]').setValue()
    await flushPromises()
    await body().find('input[type="number"]').setValue('40')

    await body().find('input[value="salary"]').setValue()
    await flushPromises()

    await clickSave()

    const [, payload] = vi.mocked(api.put).mock.calls[0]
    expect(payload.pay_type).toBe('salary')
    // The rate typed while 'commission' was selected must not survive the
    // switch to 'salary', where the API never accepts a rate at all.
    expect(payload.commission_rate).toBe(0)
  })
})
