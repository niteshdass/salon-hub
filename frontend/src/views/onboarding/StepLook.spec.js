import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls; keep everything else (including TOKEN_KEY) real
// so nothing here drifts from the actual '@/lib/api' module.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

import api from '@/lib/api'
import { THEME_SWATCHES } from '@/lib/theme'
import { useAuthStore } from '@/stores/auth'
import StepLook from './StepLook.vue'

// OnboardingLayout owns the header, the step dots, and the skip/back
// buttons — none of that is this component's job. Stub it down to its two
// slots so every assertion below targets StepLook's own markup, but keep
// the skip/back buttons wired to their emits since the skip test needs them.
const OnboardingLayoutStub = {
  name: 'OnboardingLayout',
  emits: ['skip', 'back'],
  template:
    '<div><button type="button" @click="$emit(\'skip\')">Skip for now</button><slot /><slot name="action" /></div>',
}

const ORGANIZATION_SETTINGS = {
  data: {
    data: {
      about: '',
      theme_color: '#4f46e5',
      logo_url: null,
    },
  },
}

function mountStepLook(props = {}) {
  return mount(StepLook, {
    props,
    global: { stubs: { OnboardingLayout: OnboardingLayoutStub } },
  })
}

describe('StepLook', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().setSession({
      token: 'test-token',
      user: { id: 1, name: 'Anwar' },
      organization: { id: 9, name: 'Anwar Salon' },
    })
    vi.mocked(api.get).mockReset().mockResolvedValue(ORGANIZATION_SETTINGS)
    vi.mocked(api.post).mockReset()
    vi.mocked(api.put).mockReset()
    vi.mocked(api.delete).mockReset()
  })

  async function chooseLogo(wrapper, name = 'logo.png') {
    const fileInput = wrapper.find('input[type="file"]')
    const file = new File(['logo-bytes'], name, { type: 'image/png' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await flushPromises()
    return file
  }

  it('uploads a chosen logo as multipart form data under the "image" field and shows it in the preview', async () => {
    vi.mocked(api.post).mockResolvedValue({
      data: { data: { logo_url: 'https://cdn.test/organizations/9/logo.png' } },
    })

    const wrapper = mountStepLook()
    await flushPromises()

    const fileInput = wrapper.find('input[type="file"]')
    const file = new File(['logo-bytes'], 'logo.png', { type: 'image/png' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await flushPromises()

    expect(api.post).toHaveBeenCalledTimes(1)
    const [url, body] = vi.mocked(api.post).mock.calls[0]
    expect(url).toBe('/settings/organization/logo')
    // Matches UploadOrganizationImageRequest, which only accepts the
    // request under the key 'image'.
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('image')).toBe(file)

    const preview = wrapper.find('img')
    expect(preview.exists()).toBe(true)
    expect(preview.attributes('src')).toBe('https://cdn.test/organizations/9/logo.png')
  })

  it('shows the uploaded logo as a thumbnail beside the upload control, not only in the page preview', async () => {
    vi.mocked(api.post).mockResolvedValue({
      data: { data: { logo_url: 'https://cdn.test/organizations/9/logo.png' } },
    })

    const wrapper = mountStepLook()
    await flushPromises()

    // Before any upload there is nothing to show but the empty slot.
    expect(wrapper.find('[data-test="logo-thumbnail"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="logo-empty"]').exists()).toBe(true)

    await chooseLogo(wrapper)

    const thumbnail = wrapper.find('[data-test="logo-thumbnail"]')
    expect(thumbnail.exists()).toBe(true)
    expect(thumbnail.attributes('src')).toBe('https://cdn.test/organizations/9/logo.png')
    expect(wrapper.find('[data-test="logo-empty"]').exists()).toBe(false)
  })

  it('keeps the file input in the tab order so the upload control can be reached by keyboard', async () => {
    const wrapper = mountStepLook()
    await flushPromises()

    const fileInput = wrapper.find('input[type="file"]')
    // 'hidden' (display:none) would take the input out of the tab order and
    // leave the styled label unreachable without a mouse; 'sr-only' keeps it
    // focusable and the label renders its focus ring off it via 'peer'.
    expect(fileInput.classes()).toContain('sr-only')
    expect(fileInput.classes()).not.toContain('hidden')

    // The label must point at the input by id, or clicking it does nothing.
    const id = fileInput.attributes('id')
    expect(id).toBeTruthy()
    expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true)
  })

  it('removes an uploaded logo and drops it from the thumbnail and the preview', async () => {
    vi.mocked(api.post).mockResolvedValue({
      data: { data: { logo_url: 'https://cdn.test/organizations/9/logo.png' } },
    })
    vi.mocked(api.delete).mockResolvedValue({ data: { data: { logo_url: null } } })

    const wrapper = mountStepLook()
    await flushPromises()
    await chooseLogo(wrapper)

    await wrapper.find('[data-test="logo-remove"]').trigger('click')
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/settings/organization/logo')
    expect(wrapper.find('[data-test="logo-thumbnail"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="logo-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="logo-remove"]').exists()).toBe(false)
  })

  it('does not offer to remove a logo that was never uploaded', async () => {
    const wrapper = mountStepLook()
    await flushPromises()

    expect(wrapper.find('[data-test="logo-remove"]').exists()).toBe(false)
  })

  it('saves the theme colour the owner picked, but advances without marking the step done since a colour alone does not satisfy the server\'s "look" rule', async () => {
    vi.mocked(api.put).mockResolvedValue({ data: { data: {} } })

    const wrapper = mountStepLook()
    await flushPromises()

    // Pick a non-default swatch (matches UpdateOrganizationRequest's
    // theme_color regex: #[0-9a-fA-F]{6}).
    await wrapper.find('button[aria-label="#be123c"]').trigger('click')

    await wrapper.find('button[type="button"].w-full').trigger('click')
    await flushPromises()

    expect(api.put).toHaveBeenCalledTimes(1)
    const [url, body] = vi.mocked(api.put).mock.calls[0]
    expect(url).toBe('/settings/organization')
    expect(body.theme_color).toBe('#be123c')

    // OnboardingStatus derives 'look' as filled(about) || filled(logo) — a
    // colour-only save satisfies neither, so the wizard must advance the
    // owner (this step is optional) without telling the store the step is
    // done, or the next status fetch would quietly bounce them back here.
    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('done')).toBeUndefined()
  })

  it('marks the step done when the owner also writes an about line', async () => {
    vi.mocked(api.put).mockResolvedValue({ data: { data: {} } })

    const wrapper = mountStepLook()
    await flushPromises()

    await wrapper.find('textarea').setValue('We have cut hair on this street since 1998.')
    await wrapper.find('button[type="button"].w-full').trigger('click')
    await flushPromises()

    const [, body] = vi.mocked(api.put).mock.calls[0]
    expect(body.about).toBe('We have cut hair on this street since 1998.')
    expect(wrapper.emitted('done')).toHaveLength(1)
    expect(wrapper.emitted('skip')).toBeUndefined()
  })

  it('marks the step done on a colour-only save when a logo was already uploaded', async () => {
    vi.mocked(api.post).mockResolvedValue({
      data: { data: { logo_url: 'https://cdn.test/organizations/9/logo.png' } },
    })
    vi.mocked(api.put).mockResolvedValue({ data: { data: {} } })

    const wrapper = mountStepLook()
    await flushPromises()

    const fileInput = wrapper.find('input[type="file"]')
    const file = new File(['logo-bytes'], 'logo.png', { type: 'image/png' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await flushPromises()

    // No about text is added — the logo alone must be enough, matching
    // the server's filled(about) || filled(logo) rule.
    await wrapper.find('button[type="button"].w-full').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('done')).toHaveLength(1)
    expect(wrapper.emitted('skip')).toBeUndefined()
  })

  it('saves the default swatch colour when the initial fetch never resolves a value and the owner changes nothing', async () => {
    // No theme_color in the loaded settings (a fresh organization that has
    // never saved one) — the first swatch is what the screen must fall
    // back to, and what it must still send if the owner never touches it.
    vi.mocked(api.get).mockResolvedValue({ data: { data: { about: '', theme_color: null, logo_url: null } } })
    vi.mocked(api.put).mockResolvedValue({ data: { data: {} } })

    const wrapper = mountStepLook()
    await flushPromises()

    await wrapper.find('button[type="button"].w-full').trigger('click')
    await flushPromises()

    const [, body] = vi.mocked(api.put).mock.calls[0]
    expect(body.theme_color).toBe(THEME_SWATCHES[0])
  })

  it('skipping this optional step saves nothing and leaves the owner able to move on', async () => {
    const wrapper = mountStepLook()
    await flushPromises()

    await wrapper.find('button').trigger('click') // the stubbed "Skip for now" button
    await flushPromises()

    // api.post is only reachable from uploadLogo, which this path never
    // touches, so an assertion on it here would pass regardless of whether
    // the skip wiring is correct — it belongs to the upload test, not this
    // one. api.put is the one call skip could plausibly trigger by mistake.
    expect(api.put).not.toHaveBeenCalled()
    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('done')).toBeUndefined()
  })

  it('shows a plain-language message on a rejected save and leaves the screen usable', async () => {
    vi.mocked(api.put).mockRejectedValue({
      response: {
        status: 422,
        data: {
          message: 'The theme color must be a hex value like #6366f1.',
          errors: { theme_color: ['The theme color must be a hex value like #6366f1.'] },
        },
      },
    })

    const wrapper = mountStepLook()
    await flushPromises()

    const continueButton = wrapper.find('button[type="button"].w-full')
    await continueButton.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('The theme color must be a hex value like #6366f1.')
    expect(wrapper.emitted('done')).toBeUndefined()

    // The owner must not be left stuck behind a disabled button after a
    // failed save.
    expect(continueButton.attributes('disabled')).toBeUndefined()
    expect(continueButton.text()).toBe('Continue')
  })
})
