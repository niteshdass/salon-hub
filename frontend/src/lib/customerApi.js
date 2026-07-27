import axios from 'axios'

// Separate token + client from staff auth so the two sessions never collide.
export const CUSTOMER_TOKEN_KEY = 'salonhub_customer_token'

const customerApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
})

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
