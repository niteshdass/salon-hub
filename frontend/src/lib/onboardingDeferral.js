// "I'll do this later", remembered for exactly as long as it should be.
//
// The router guard diverts an owner whose salon has no `onboarding_completed_at`
// into the setup wizard. Every exit from that wizard that is NOT a completion —
// "Skip for now" on a required step, "Back" from the first screen, "I'll do this
// later" on the success screen — deliberately saves nothing, so the guard would
// otherwise reverse the very navigation the button just made and the owner would
// be stuck inside the wizard with no working way out.
//
// So the deferral is remembered here instead of on the organization: the owner
// is not asked again in this browser session, but the next time they arrive
// fresh the wizard greets them again, which is what the spec asks for and what
// the dashboard's setup card is there to make bearable.
//
// Scoped to the organization id so that signing out and signing back in as the
// owner of a *different* salon in the same tab does not inherit the first
// owner's "later" and rob a brand-new salon of its first-run setup.
//
// sessionStorage, not localStorage: "later" must not mean "forever".
const DEFERRED_KEY = 'onboarding-deferred-org'

export function deferOnboarding(organizationId) {
  try {
    sessionStorage.setItem(DEFERRED_KEY, String(organizationId ?? ''))
  } catch {
    // Safari's private mode can throw on write. Failing to remember the
    // deferral is a nag, not a broken app — never let it break the exit.
  }
}

export function onboardingDeferred(organizationId) {
  try {
    // getItem returns null when nothing was ever stored, so a missing
    // organization id can never accidentally match a stored one.
    return sessionStorage.getItem(DEFERRED_KEY) === String(organizationId ?? '')
  } catch {
    return false
  }
}
