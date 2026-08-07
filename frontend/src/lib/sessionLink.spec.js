import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { TOKEN_KEY } from '@/lib/api'
import { CUSTOMER_TOKEN_KEY } from '@/lib/customerApi'
import { useSessionLink } from './sessionLink'

describe('useSessionLink', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('is null for a visitor with no session', () => {
    expect(useSessionLink().value).toBeNull()
  })

  it('sends a signed-in customer to their bookings', () => {
    localStorage.setItem(CUSTOMER_TOKEN_KEY, 'customer-token')

    expect(useSessionLink().value).toEqual({ label: 'Manage bookings', to: '/account' })
  })

  it('sends signed-in staff to their salon', () => {
    localStorage.setItem(TOKEN_KEY, 'staff-token')

    expect(useSessionLink().value).toEqual({ label: 'Manage your salon', to: '/dashboard' })
  })

  it('prefers the salon when the same person holds both sessions', () => {
    localStorage.setItem(TOKEN_KEY, 'staff-token')
    localStorage.setItem(CUSTOMER_TOKEN_KEY, 'customer-token')

    expect(useSessionLink().value).toEqual({ label: 'Manage your salon', to: '/dashboard' })
  })
})
