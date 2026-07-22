import axios from 'axios'

// Shared axios client. Dev requests to /api are proxied to the Laravel
// backend by Vite (see vite.config.js). In production set VITE_API_URL.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    Accept: 'application/json',
  },
})

export default api
