import { describe, it, expect } from 'vitest'
import {
  BRAND_ACCENT,
  INK,
  THEME_SWATCHES,
  normalizeAccent,
  accentForeground,
  applyAccent,
} from '@/lib/theme'

describe('normalizeAccent', () => {
  it('keeps a salon colour the owner actually chose', () => {
    expect(normalizeAccent('#0F766E')).toBe('#0f766e')
  })

  it('accepts a hex without the leading hash', () => {
    expect(normalizeAccent('0f766e')).toBe('#0f766e')
  })

  it('reads the API default as "never chosen" and returns the brand terracotta', () => {
    expect(normalizeAccent('#6366f1')).toBe(BRAND_ACCENT)
    expect(normalizeAccent('#6366F1')).toBe(BRAND_ACCENT)
  })

  it('falls back to the brand for anything unusable', () => {
    expect(normalizeAccent(null)).toBe(BRAND_ACCENT)
    expect(normalizeAccent(undefined)).toBe(BRAND_ACCENT)
    expect(normalizeAccent('')).toBe(BRAND_ACCENT)
    expect(normalizeAccent('teal')).toBe(BRAND_ACCENT)
    expect(normalizeAccent('#fff')).toBe(BRAND_ACCENT)
    expect(normalizeAccent(42)).toBe(BRAND_ACCENT)
  })
})

describe('accentForeground', () => {
  it('puts white text on a dark accent', () => {
    expect(accentForeground('#0f766e')).toBe('#ffffff')
    expect(accentForeground(BRAND_ACCENT)).toBe('#ffffff')
  })

  it('flips to ink on a pale accent so the label stays readable', () => {
    expect(accentForeground('#fde68a')).toBe(INK)
    expect(accentForeground('#ffffff')).toBe(INK)
  })
})

describe('applyAccent', () => {
  it('writes both custom properties on the given root', () => {
    const root = document.createElement('div')

    const applied = applyAccent('#0F766E', root)

    expect(applied).toBe('#0f766e')
    expect(root.style.getPropertyValue('--color-accent')).toBe('#0f766e')
    expect(root.style.getPropertyValue('--color-accent-fg')).toBe('#ffffff')
  })

  it('writes the brand terracotta when the salon never chose', () => {
    const root = document.createElement('div')

    applyAccent('#6366f1', root)

    expect(root.style.getPropertyValue('--color-accent')).toBe(BRAND_ACCENT)
  })
})

describe('THEME_SWATCHES', () => {
  it('leads with the brand terracotta and holds eight normalized hexes', () => {
    expect(THEME_SWATCHES[0]).toBe(BRAND_ACCENT)
    expect(THEME_SWATCHES).toHaveLength(8)
    THEME_SWATCHES.forEach((hex) => expect(normalizeAccent(hex)).toBe(hex))
  })
})
