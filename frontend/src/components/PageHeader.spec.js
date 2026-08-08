import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PageHeader from '@/components/PageHeader.vue'

describe('PageHeader', () => {
  it('renders the title and subtitle', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Calendar', subtitle: '1 appointment in this view.' },
    })

    expect(wrapper.find('h1').text()).toBe('Calendar')
    expect(wrapper.text()).toContain('1 appointment in this view.')
  })

  it('omits the subtitle line when there is nothing to say', () => {
    const wrapper = mount(PageHeader, { props: { title: 'Staff' } })

    expect(wrapper.find('p').exists()).toBe(false)
  })

  it('renders a page action when one is provided', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Appointments' },
      slots: { actions: '<button>New booking</button>' },
    })

    expect(wrapper.find('button').text()).toBe('New booking')
  })
})
