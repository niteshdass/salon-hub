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
