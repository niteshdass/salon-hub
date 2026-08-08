import { ref } from 'vue'
import { defineStore } from 'pinia'
import { ACCENT_STORAGE_KEY, BRAND_ACCENT, applyAccent, normalizeAccent } from '@/lib/theme'

/*
 * The accent the admin surface is wearing. Seeded from localStorage so a
 * reload does not flash terracotta before /auth/me answers, then corrected
 * by the session payload and by the settings picker's live preview.
 */
export const useThemeStore = defineStore('theme', () => {
  const accent = ref(normalizeAccent(localStorage.getItem(ACCENT_STORAGE_KEY)))

  function setAccent(value) {
    accent.value = applyAccent(value)
    localStorage.setItem(ACCENT_STORAGE_KEY, accent.value)
  }

  // Logging out must not leave the next salon — or the login page — wearing
  // the previous tenant's colour.
  function reset() {
    accent.value = applyAccent(BRAND_ACCENT)
    localStorage.removeItem(ACCENT_STORAGE_KEY)
  }

  return { accent, setAccent, reset }
})
