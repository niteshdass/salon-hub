import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() } }
})

import api from '@/lib/api'
import SalonProfileSettings from '@/components/settings/SalonProfileSettings.vue'
import { useThemeStore } from '@/stores/theme'
import { BRAND_ACCENT, THEME_SWATCHES } from '@/lib/theme'

const PROFILE = {
  name: 'Heaven Touch',
  email: 'owner@heaven.test',
  phone: '',
  country: 'BD',
  timezone: 'Asia/Dhaka',
  currency: 'BDT',
  theme_color: '#6366f1',
  about: '',
  facebook: '',
  instagram: '',
  website: '',
  slug: 'heaven',
  logo_url: null,
  cover_image_url: null,
}

async function mountSettings() {
  vi.mocked(api.get).mockResolvedValue({ data: { data: { ...PROFILE } } })
  const wrapper = mount(SalonProfileSettings)
  await flushPromises()
  return wrapper
}

describe('SalonProfileSettings — theme picker', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.put).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('offers every curated swatch', async () => {
    const wrapper = await mountSettings()

    expect(wrapper.findAll('[data-swatch]')).toHaveLength(THEME_SWATCHES.length)
  })

  it('previews the chosen colour immediately, before saving', async () => {
    const wrapper = await mountSettings()
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')

    expect(useThemeStore().accent).toBe('#0f766e')
  })

  it('saves the chosen colour with the profile', async () => {
    const wrapper = await mountSettings()
    // save() hands the reactive form straight to axios and then writes the
    // response back into it, so the recorded call argument is a live
    // reference that already holds the mocked reply by assertion time.
    // Snapshot the body as it is sent or this test proves nothing.
    let sent
    vi.mocked(api.put).mockImplementation((url, body) => {
      sent = { ...body }
      return Promise.resolve({ data: { data: { ...PROFILE, theme_color: '#0f766e' } } })
    })
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sent.theme_color).toBe('#0f766e')
  })

  it('shows a salon that never chose a colour as unset, not as the placeholder hex', async () => {
    const wrapper = await mountSettings()

    // The stored '#6366f1' is a sentinel the public pages read as their own
    // gold. It must stay in the database, but printing it here would name a
    // colour that appears nowhere on screen.
    expect(wrapper.find('[data-theme-hex]').element.value).toBe('')
    expect(wrapper.find('[data-theme-picker]').element.value).toBe(BRAND_ACCENT)
    expect(wrapper.findAll('[data-swatch][aria-pressed="true"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('Not set')
  })

  it('corrects a junk custom hex on blur instead of letting it reach the server', async () => {
    const wrapper = await mountSettings()
    const field = wrapper.find('[data-theme-hex]')

    await field.setValue('not-a-colour')
    await field.trigger('change')

    expect(field.element.value).toBe(BRAND_ACCENT)
    expect(useThemeStore().accent).toBe(BRAND_ACCENT)
  })

  it('treats an emptied hex field as no edit rather than a choice of terracotta', async () => {
    const wrapper = await mountSettings()
    const field = wrapper.find('[data-theme-hex]')

    await field.setValue('')
    await field.trigger('change')

    // normalizeAccent('') answers brand terracotta. Committing it would write a
    // real colour over the sentinel and move the salon's public page off gold.
    expect(field.element.value).toBe('')
    expect(wrapper.text()).toContain('Not set')
  })

  it('keeps an unsaved colour pick through a logo upload', async () => {
    const wrapper = await mountSettings()
    vi.mocked(api.post).mockResolvedValue({
      data: { data: { ...PROFILE, logo_url: 'https://cdn.test/logo.png' } },
    })
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')

    const fileInput = wrapper.find('input[type="file"]')
    const file = new File(['logo-bytes'], 'logo.png', { type: 'image/png' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await flushPromises()

    // The upload response carries the salon's stored theme_color; letting it
    // land would snap the app back mid-decision.
    expect(useThemeStore().accent).toBe('#0f766e')
  })

  it('drops an unsaved preview when the page goes away', async () => {
    const wrapper = await mountSettings()
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')
    wrapper.unmount()

    // Nothing was saved, so the app returns to the colour the server last
    // confirmed — here the sentinel, which reads as brand terracotta.
    expect(useThemeStore().accent).toBe(BRAND_ACCENT)
  })
})
