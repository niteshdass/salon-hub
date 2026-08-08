import axios from 'axios'

// Separate token + client from staff auth so the two sessions never collide.
export const CUSTOMER_TOKEN_KEY = 'salonhub_customer_token'

const baseURL = import.meta.env.VITE_API_URL || '/api'

const customerApi = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
})

/** The stored customer token, or null. */
export function customerToken() {
  return localStorage.getItem(CUSTOMER_TOKEN_KEY)
}

/**
 * Resolve the signed-in customer without the 401 redirect below. A salon's
 * public booking page may be opened by anyone: a missing or stale token means
 * "book as a guest", never "bounce this visitor to the account login".
 */
export async function fetchCustomerIdentity() {
  const token = customerToken()
  if (!token) return null

  try {
    const { data } = await axios.get(`${baseURL}/customer/auth/me`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
    return data.account
  } catch {
    return null
  }
}

customerApi.interceptors.request.use((config) => {
  const token = localStorage.getItem(CUSTOMER_TOKEN_KEY)
  if (token) {
    config.headers = config.headers || {}
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// On an expired/invalid customer token, clear it and bounce to the customer
// login (never the staff /login).
customerApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem(CUSTOMER_TOKEN_KEY)
      if (window.location.pathname !== '/account/login') {
        window.location.assign('/account/login')
      }
    }
    return Promise.reject(error)
  },
)

export default customerApi
