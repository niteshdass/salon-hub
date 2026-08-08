/*
 * The salon's accent. One hex arrives from the API; everything the UI needs —
 * the shade ramp, the text colour that sits on top — is derived from it. The
 * ramp itself lives in CSS (color-mix over --color-accent); only the two
 * custom properties below are written from JS.
 */

// SalonHub's own terracotta. Also the answer whenever a salon has not chosen.
export const BRAND_ACCENT = '#c65d3b'
export const INK = '#241c18'

// The settings column defaults to this indigo, so a row holding it tells us
// nothing about the owner's taste. SalonSiteView.vue reads it the same way.
const UNCHOSEN = '#6366f1'

export const ACCENT_STORAGE_KEY = 'salonhub.accent'

// Offered in the settings picker and the onboarding wizard — imported by both
// so the two screens cannot drift apart.
export const THEME_SWATCHES = [
  BRAND_ACCENT, // terracotta
  '#be123c', // rose
  '#b45309', // amber
  '#166534', // forest
  '#0f766e', // teal
  '#0369a1', // blue
  '#7c3aed', // violet
  '#334155', // slate
]

const HEX = /^#?([0-9a-f]{6})$/i

export function normalizeAccent(value) {
  if (typeof value !== 'string') return BRAND_ACCENT

  const match = HEX.exec(value.trim())
  if (!match) return BRAND_ACCENT

  const hex = `#${match[1].toLowerCase()}`

  return hex === UNCHOSEN ? BRAND_ACCENT : hex
}

function linearChannel(value) {
  const channel = value / 255

  return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
}

export function relativeLuminance(hex) {
  const digits = normalizeAccent(hex).slice(1)
  const r = linearChannel(parseInt(digits.slice(0, 2), 16))
  const g = linearChannel(parseInt(digits.slice(2, 4), 16))
  const b = linearChannel(parseInt(digits.slice(4, 6), 16))

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/*
 * No swatch has to be forbidden: a pale accent simply gets dark text. The
 * threshold sits well above mid-grey because white-on-accent is the house
 * look — only genuinely light hues should flip.
 */
export function accentForeground(hex) {
  return relativeLuminance(hex) >= 0.55 ? INK : '#ffffff'
}

export function applyAccent(hex, root = document.documentElement) {
  const accent = normalizeAccent(hex)

  root.style.setProperty('--color-accent', accent)
  root.style.setProperty('--color-accent-fg', accentForeground(accent))

  return accent
}
