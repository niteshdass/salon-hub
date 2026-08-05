import axios from 'axios'

// localStorage key used to persist the Bearer token.
export const TOKEN_KEY = 'salonhub_token'

// Shared axios client. Dev requests to /api are proxied to the Laravel
// backend by Vite (see vite.config.js). In production set VITE_API_URL.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    Accept: 'application/json',
  },
})

// Attach the Bearer token (when present) to every outgoing request.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers = config.headers || {}
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// On an expired/invalid token, clear it and bounce to the login page.
// The store also reads from localStorage on init, so clearing here keeps
// things consistent without importing the store (avoids a circular dep).
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }
    if (error.response && error.response.status >= 500) {
      // A server fault is never the user's fault to interpret — surface it
      // rather than let a view render an empty state that looks like "no data".
      console.error('SalonHub API error', error.response.status, error.config?.url)
    }
    return Promise.reject(error)
  },
)

export default api
