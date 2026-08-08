import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useThemeStore } from '@/stores/theme'
import { BRAND_ACCENT, ACCENT_STORAGE_KEY } from '@/lib/theme'

describe('theme store', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.style.removeProperty('--color-accent')
    setActivePinia(createPinia())
  })

  it('starts on the brand terracotta when nothing was remembered', () => {
    expect(useThemeStore().accent).toBe(BRAND_ACCENT)
  })

  it('paints the document and remembers the choice', () => {
    const theme = useThemeStore()

    theme.setAccent('#0F766E')

    expect(theme.accent).toBe('#0f766e')
    expect(document.documentElement.style.getPropertyValue('--color-accent')).toBe('#0f766e')
    expect(localStorage.getItem(ACCENT_STORAGE_KEY)).toBe('#0f766e')
  })

  it('restores the remembered colour on a fresh store', () => {
    localStorage.setItem(ACCENT_STORAGE_KEY, '#be123c')

    expect(useThemeStore().accent).toBe('#be123c')
  })

  it('normalizes rubbish instead of trusting it', () => {
    const theme = useThemeStore()

    theme.setAccent('not-a-colour')

    expect(theme.accent).toBe(BRAND_ACCENT)
  })

  it('returns to the brand on reset, forgetting the salon', () => {
    const theme = useThemeStore()
    theme.setAccent('#0f766e')

    theme.reset()

    expect(theme.accent).toBe(BRAND_ACCENT)
    expect(localStorage.getItem(ACCENT_STORAGE_KEY)).toBeNull()
  })
})
