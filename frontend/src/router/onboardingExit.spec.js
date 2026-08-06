import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Only the axios calls are mocked. There is deliberately NO
// `vi.mock('vue-router', ...)` anywhere in this file: every other spec on
// this feature mocks it, which turns `router.push('/dashboard')` into a spy
// that always succeeds, and that is precisely why an owner who could never
// actually leave the wizard shipped twice. The whole point of this file is
// that the real guard gets to answer the real navigation.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }
})

import api from '@/lib/api'
import router from './index'
import { deferOnboarding } from '@/lib/onboardingDeferral'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import OnboardingView from '@/views/onboarding/OnboardingView.vue'

const ORG_ID = 9

function signInAsOwner({ organizationId = ORG_ID, completedAt = null } = {}) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Anwar', role: 'owner' },
    organization: { id: organizationId, name: 'Beauty Queen', onboarding_completed_at: completedAt },
  })
}

const freshStatus = {
  data: {
    data: {
      completed_at: null,
      branch_id: 7,
      steps: { branch: false, services: false, staff: false, look: false },
      next_step: 'branch',
    },
  },
}

// Emits 'skip' on click, so the click travels through OnboardingView's real
// skip() -> leave() rather than the test reaching in and calling it.
const SkippableStub = {
  props: ['branchId'],
  emits: ['done', 'skip', 'back'],
  template: '<button type="button" data-test="skip-btn" @click="$emit(\'skip\')" />',
}

const mountWizard = () =>
  mount(OnboardingView, {
    global: {
      // The real router, installed — so useRouter() inside the component is
      // the same instance whose guard this file is testing.
      plugins: [router],
      stubs: {
        StepBranch: SkippableStub,
        StepServices: { template: '<div data-test="services" />' },
        StepStaff: { template: '<div data-test="staff" />' },
        StepLook: { template: '<div data-test="look" />' },
        StepDone: { template: '<div data-test="done" />' },
      },
    },
  })

describe('leaving the onboarding wizard, driven through the real router', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    sessionStorage.clear()
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
    vi.mocked(api.put).mockReset()
    // The router is a module singleton shared by every test in this file.
    // Park it on a public route first, so each test's own navigation is a
    // real one and not silently aborted as a duplicate of the last one.
    await router.replace('/terms')
  })

  it('sends an owner who has never started setup to the wizard when they aim for the dashboard', async () => {
    signInAsOwner()

    await router.push('/dashboard')

    expect(router.currentRoute.value.path).toBe('/onboarding')
  })

  it('lands an owner who skips a required step on the dashboard, instead of leaving them where they were', async () => {
    // This is the bug in full: "Skip for now" on the branch screen used to
    // push to /dashboard, the guard reversed it to /onboarding, vue-router
    // aborted that as a duplicate of the current location, and the owner sat
    // looking at a screen that had done nothing at all.
    signInAsOwner()
    vi.mocked(api.get).mockResolvedValue(freshStatus)

    await router.push('/onboarding')
    expect(router.currentRoute.value.path).toBe('/onboarding')

    const wrapper = mountWizard()
    await flushPromises()

    await wrapper.find('[data-test="skip-btn"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/dashboard')
    // Skipping still saves nothing: the owner has finished nothing, so the
    // dashboard card must still have something to nag about.
    expect(useAuthStore().organization.onboarding_completed_at).toBeNull()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('lands an owner on the dashboard after a real complete(), which is the other exit', async () => {
    signInAsOwner()
    vi.mocked(api.post).mockResolvedValue({
      data: {
        data: {
          completed_at: '2026-08-06T10:00:00+00:00',
          branch_id: 7,
          steps: { branch: true, services: true, staff: true, look: true },
          next_step: 'done',
        },
      },
    })

    await useOnboardingStore().complete()
    await router.push('/dashboard')

    expect(router.currentRoute.value.path).toBe('/dashboard')
  })

  it('asks again on a fresh session, and never lets one salon\'s "later" cover another salon', async () => {
    signInAsOwner()
    deferOnboarding(ORG_ID)

    await router.push('/dashboard')
    expect(router.currentRoute.value.path).toBe('/dashboard')

    // A brand new salon whose owner signs in in the same tab must still get
    // their first-run setup — "later" belongs to the salon that said it.
    await router.replace('/terms')
    signInAsOwner({ organizationId: 10 })

    await router.push('/dashboard')
    expect(router.currentRoute.value.path).toBe('/onboarding')

    // And a new browser session (no sessionStorage) greets the original
    // owner with the wizard again, rather than "later" meaning "forever".
    await router.replace('/terms')
    sessionStorage.clear()
    signInAsOwner()

    await router.push('/dashboard')
    expect(router.currentRoute.value.path).toBe('/onboarding')
  })
})
