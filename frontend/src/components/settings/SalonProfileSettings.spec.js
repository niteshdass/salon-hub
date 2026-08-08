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
import { THEME_SWATCHES } from '@/lib/theme'

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
    vi.mocked(api.put).mockResolvedValue({ data: { data: { ...PROFILE, theme_color: '#0f766e' } } })
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(api.put).mock.calls[0][1].theme_color).toBe('#0f766e')
  })
})
