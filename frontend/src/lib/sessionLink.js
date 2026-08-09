import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useCustomerAuthStore } from '@/stores/customerAuth'

/**
 * Where a signed-in visitor goes from a public page — the marketing home and
 * every salon's shopfront ask the same question, so they get one answer.
 *
 * A staff session outranks a customer one: an owner can hold both at once
 * (they book haircuts too), and on a page that offers a single link the salon
 * they run is the stronger claim. Null when neither session exists, which
 * leaves the page's signed-out links alone.
 */
export function useSessionLink() {
  const staff = useAuthStore()
  const customer = useCustomerAuthStore()

  return computed(() => {
    if (staff.isAuthenticated) return { label: 'Manage your salon', to: '/dashboard' }
    if (customer.isAuthenticated) return { label: 'Manage bookings', to: '/account' }
    return null
  })
}

// The signed-out half of the same link. A customer who booked last month has
// no idea the salon's "Log in" belongs to the salon's own staff, so every
// public surface offers this one instead of leaving them nowhere to go.
const CUSTOMER_SIGN_IN = { label: 'My bookings', to: '/account/login' }

/**
 * Like useSessionLink(), but never null: a visitor with no session is offered
 * the customer sign-in rather than nothing. Use this where the page has room
 * for exactly one "your stuff is over here" link; use useSessionLink() where
 * signed-out visitors are meant to see the page's own signed-out links.
 */
export function useAccountLink() {
  const session = useSessionLink()

  return computed(() => session.value || CUSTOMER_SIGN_IN)
}
