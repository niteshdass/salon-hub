import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls; keep everything else (including TOKEN_KEY) real
// so nothing here drifts from the actual '@/lib/api' module.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
  }
})

import api from '@/lib/api'
import StepBranch from './StepBranch.vue'

// OnboardingLayout owns the header, the step dots, and the skip/back
// buttons — none of that is this component's job. Stub it down to its two
// slots so every assertion below targets StepBranch's own markup.
const OnboardingLayoutStub = {
  name: 'OnboardingLayout',
  template: '<div><slot /><slot name="action" /></div>',
}

function mountStepBranch(props = {}) {
  return mount(StepBranch, {
    props: { branchId: null, ...props },
    global: { stubs: { OnboardingLayout: OnboardingLayoutStub } },
  })
}

// DAYS is declared inline in the component (not exported), so tests find a
// day's row by its fixed position in that list rather than duplicating it.
const DAY_INDEX = { mon: 0, tue: 1, wed: 2, thu: 3, fri: 4, sat: 5, sun: 6 }
const dayRow = (wrapper, key) => wrapper.findAll('li')[DAY_INDEX[key]]
const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

describe('StepBranch', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.put).mockReset()
  })

  it('hydrates the address, city, phone and per-day hours from the existing branch', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        data: {
          address: '12 Green Road, Dhanmondi',
          city: 'Dhaka',
          phone: '+8801700000000',
          // tue proves a stored `null` renders closed; wed proves a stored
          // pair renders open with those exact times.
          opening_hours_json: { tue: null, wed: ['10:00', '19:00'] },
        },
      },
    })

    const wrapper = mountStepBranch({ branchId: 42 })
    await flushPromises()

    expect(wrapper.get('input[placeholder="12 Green Road, Dhanmondi"]').element.value).toBe(
      '12 Green Road, Dhanmondi',
    )
    expect(wrapper.findAll('input[type="text"]')[1].element.value).toBe('Dhaka')
    expect(wrapper.get('input[type="tel"]').element.value).toBe('+8801700000000')

    // tue defaults to open in the component's own initial state, so this
    // only passes if the fetched `null` actually overrode it.
    const tue = dayRow(wrapper, 'tue')
    expect(tue.get('input[type="checkbox"]').element.checked).toBe(false)
    expect(tue.text()).toContain('Closed')

    const wed = dayRow(wrapper, 'wed')
    expect(wed.get('input[type="checkbox"]').element.checked).toBe(true)
    const wedTimes = wed.findAll('input[type="time"]')
    expect(wedTimes[0].element.value).toBe('10:00')
    expect(wedTimes[1].element.value).toBe('19:00')
  })

  it('does not block setup when the existing branch cannot be read', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('Network error'))

    const wrapper = mountStepBranch({ branchId: 42 })
    await flushPromises()

    // Still renders, and shows nothing that reads as an error to the owner.
    expect(wrapper.get('h2').text()).toBe('When are you open?')
    expect(wrapper.find('.bg-rose-50').exists()).toBe(false)

    // Registration's own defaults: open Mon-Sat 09:00-18:00, Sunday closed.
    const mon = dayRow(wrapper, 'mon')
    expect(mon.get('input[type="checkbox"]').element.checked).toBe(true)
    const monTimes = mon.findAll('input[type="time"]')
    expect(monTimes[0].element.value).toBe('09:00')
    expect(monTimes[1].element.value).toBe('18:00')

    const sun = dayRow(wrapper, 'sun')
    expect(sun.get('input[type="checkbox"]').element.checked).toBe(false)
  })

  it('"Same time every day" copies Monday onto every open day and leaves closed days alone', async () => {
    const wrapper = mountStepBranch({ branchId: null })
    await flushPromises()

    const mon = dayRow(wrapper, 'mon')
    const monTimes = mon.findAll('input[type="time"]')
    await monTimes[0].setValue('10:00')
    await monTimes[1].setValue('20:00')

    // Deliberately close Wednesday first, so the shortcut has a closed day
    // it is required to skip.
    const wed = dayRow(wrapper, 'wed')
    await wed.get('input[type="checkbox"]').setValue(false)

    await buttonNamed(wrapper, 'Same time every day').trigger('click')

    // Every day still open — not just one — must now read Monday's times.
    for (const key of ['tue', 'thu', 'fri', 'sat']) {
      const times = dayRow(wrapper, key).findAll('input[type="time"]')
      expect(times[0].element.value).toBe('10:00')
      expect(times[1].element.value).toBe('20:00')
    }

    // Wednesday must still read closed...
    expect(wed.get('input[type="checkbox"]').element.checked).toBe(false)
    expect(wed.text()).toContain('Closed')
    // ...and reopening it to inspect its times proves the shortcut never
    // touched them while it was closed.
    await wed.get('input[type="checkbox"]').setValue(true)
    const wedTimes = wed.findAll('input[type="time"]')
    expect(wedTimes[0].element.value).toBe('09:00')
    expect(wedTimes[1].element.value).toBe('18:00')

    // Sunday was already closed by default and must remain so too.
    expect(dayRow(wrapper, 'sun').get('input[type="checkbox"]').element.checked).toBe(false)
  })

  it('sends the trimmed address, null for blank fields, and an [from, to] pair or null per day', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('not found'))
    vi.mocked(api.put).mockResolvedValue({})

    const wrapper = mountStepBranch({ branchId: 42 })
    await flushPromises()

    await wrapper.get('input[placeholder="12 Green Road, Dhanmondi"]').setValue('  12 Green Road  ')
    // Close Friday deliberately, alongside Sunday's default-closed day, so
    // the payload has more than one `null` to check.
    await dayRow(wrapper, 'fri').get('input[type="checkbox"]').setValue(false)

    await buttonNamed(wrapper, 'Continue').trigger('click')
    await flushPromises()

    expect(api.put).toHaveBeenCalledTimes(1)
    const [url, body] = vi.mocked(api.put).mock.calls[0]
    expect(url).toBe('/branches/42')
    expect(body.address).toBe('12 Green Road')
    expect(body.city).toBeNull()
    expect(body.phone).toBeNull()
    // A pair for a day that stayed open — this is what SlotGenerator reads
    // to build bookable slots.
    expect(body.opening_hours_json.mon).toEqual(['09:00', '18:00'])
    // null, not a pair, for a day the owner just shut — sending a pair here
    // would let customers book a day the salon is closed.
    expect(body.opening_hours_json.fri).toBeNull()
    expect(body.opening_hours_json.sun).toBeNull()

    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('keeps Continue disabled without an address, and a click while disabled sends nothing', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('not found'))
    const wrapper = mountStepBranch({ branchId: 42 })
    await flushPromises()

    const continueButton = buttonNamed(wrapper, 'Continue')
    expect(continueButton.attributes('disabled')).toBeDefined()

    // Same reason as the test below: the native `disabled` attribute stops a
    // jsdom click by itself, so clicking it as rendered would prove nothing
    // about save()'s own guard. Force it through.
    continueButton.element.removeAttribute('disabled')
    await continueButton.trigger('click')
    await flushPromises()

    expect(api.put).not.toHaveBeenCalled()
  })

  it('says why it cannot save, rather than offering an enabled Continue, when there is no branch behind the screen', async () => {
    // Reachable two ways: the status read failed on mount so the host has no
    // branch_id to pass down, or the response carried a null branch_id (an
    // org whose only branch row was deleted). Both used to produce an enabled
    // Continue that hit `if (!props.branchId) return` and said nothing.
    const wrapper = mountStepBranch({ branchId: null })
    await flushPromises()

    // Address filled in, so nothing but the missing branch can be blocking.
    await wrapper.get('input[placeholder="12 Green Road, Dhanmondi"]').setValue('12 Green Road')

    const continueButton = buttonNamed(wrapper, 'Continue')
    expect(continueButton.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain("We couldn't find your salon's location")
    // The reason must be usable by someone who has never heard of a "branch".
    expect(wrapper.text()).not.toContain('branch_id')

    // A native `disabled` attribute suppresses a jsdom click on its own, so
    // the assertion below would pass whether or not save()'s guard works.
    // Force the click through to exercise the guard inside save() itself.
    continueButton.element.removeAttribute('disabled')
    await continueButton.trigger('click')
    await flushPromises()

    expect(api.put).not.toHaveBeenCalled()
    expect(wrapper.emitted('done')).toBeUndefined()
  })

  it('explains a rejected save in plain language, without emitting done or leaking the field name', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('not found'))
    vi.mocked(api.put).mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'Please add a street address customers can find you at.',
          errors: { address: ['Please add a street address customers can find you at.'] },
        },
      },
    })

    const wrapper = mountStepBranch({ branchId: 42 })
    await flushPromises()

    await wrapper.get('input[placeholder="12 Green Road, Dhanmondi"]').setValue('12 Green Road')
    await buttonNamed(wrapper, 'Continue').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Please add a street address customers can find you at.')
    expect(wrapper.text()).not.toContain('opening_hours_json')
    expect(wrapper.emitted('done')).toBeUndefined()

    // The `finally` in save() must hand control back to the owner — a
    // rejected save that leaves the button disabled traps them.
    const continueButton = buttonNamed(wrapper, 'Continue')
    expect(continueButton.attributes('disabled')).toBeUndefined()
    expect(continueButton.text()).toBe('Continue')
  })
})
