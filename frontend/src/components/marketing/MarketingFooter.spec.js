import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))
vi.mock('@/lib/api', () => ({ default: { post: vi.fn() } }))

import api from '@/lib/api'
import MarketingFooter from './MarketingFooter.vue'

const fill = async (wrapper) => {
  await wrapper.find('#footer-contact-name').setValue('Rupali')
  await wrapper.find('#footer-contact-email').setValue('rupali@salon.test')
  await wrapper.find('#footer-contact-message').setValue('Do you support two branches?')
}

describe('MarketingFooter', () => {
  beforeEach(() => vi.mocked(api.post).mockReset())

  it('calls the product Glowhub', () => {
    const wrapper = mount(MarketingFooter)

    expect(wrapper.text()).toContain('Glowhub')
    expect(wrapper.text()).not.toContain('SalonHub')
  })

  it('sends the message to the contact endpoint', async () => {
    vi.mocked(api.post).mockResolvedValue({})
    const wrapper = mount(MarketingFooter)

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/contact', {
      name: 'Rupali',
      email: 'rupali@salon.test',
      message: 'Do you support two branches?',
    })
    expect(wrapper.text()).toContain("Thanks — we'll be in touch")
  })

  it('shows the field errors the server returns', async () => {
    // mockRejectedValueOnce (not mockRejectedValue): the submit path awaits
    // and catches this exactly once, but a persistent mockRejectedValue
    // implementation leaves a second, later-created rejected promise (from
    // Vitest's own mock bookkeeping) floating with no handler attached,
    // which Vitest's jsdom pool reports as an unhandled rejection against
    // whichever test is active — a false failure unrelated to this
    // component's behavior. Confirmed via api.post.mock.calls.length === 1.
    vi.mocked(api.post).mockRejectedValueOnce({ response: { status: 422, data: { errors: { email: ['Not an email.'] } } } })
    const wrapper = mount(MarketingFooter)

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Not an email.')
  })

  it('says so plainly when the visitor is rate limited', async () => {
    // See note above: mockRejectedValueOnce avoids a spurious unhandled-
    // rejection failure unrelated to the assertions in this test.
    vi.mocked(api.post).mockRejectedValueOnce({ response: { status: 429 } })
    const wrapper = mount(MarketingFooter)

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Too many messages')
  })
})
