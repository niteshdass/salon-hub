import axios from 'axios'
import { ACCENT_STORAGE_KEY } from '@/lib/theme'

// localStorage key used to persist the Bearer token.
export const TOKEN_KEY = 'salonhub_token'

// Shared axios client. Dev requests to /api are proxied to the Laravel backend
// by Vite (see vite.config.js). In production both vhosts serve the SPA and the
// API from one origin, so the same-origin '/api' default is correct and needs
// no CORS — VITE_API_URL is only for an API on a different origin. (Do not
// confuse it with VITE_APP_DOMAIN, which IS required in production; see
// frontend/.env.example.)
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    Accept: 'application/json',
  },
})

// Attach the staff Bearer token (when present) to every outgoing request. A
// caller that set its own Authorization keeps it: the public booking page
// sends a *customer* token on an endpoint that is otherwise anonymous, and a
// salon owner browsing their own booking site must not overwrite it.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token && !config.headers?.Authorization) {
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
      // Drop the remembered accent too: the reload that follows re-runs
      // main.js, which repaints from this key before any store exists — a
      // stale entry would wear the previous tenant's colour on /login.
      localStorage.removeItem(ACCENT_STORAGE_KEY)
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
