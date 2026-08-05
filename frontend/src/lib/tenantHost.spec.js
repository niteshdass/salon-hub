import { describe, it, expect, afterEach } from 'vitest'
import { resolveSlugFromHost, publicApiBase } from './tenantHost'

describe('resolveSlugFromHost', () => {
  it('reads the slug from a salon subdomain', () => {
    expect(resolveSlugFromHost('beauty-queen.salonhub.com', 'salonhub.com')).toBe('beauty-queen')
  })

  it('returns null on the apex, which is the marketing site', () => {
    expect(resolveSlugFromHost('salonhub.com', 'salonhub.com')).toBeNull()
  })

  it('returns null for reserved product hosts', () => {
    expect(resolveSlugFromHost('app.salonhub.com', 'salonhub.com')).toBeNull()
    expect(resolveSlugFromHost('www.salonhub.com', 'salonhub.com')).toBeNull()
  })

  // The brief's original example here was 'salonhub.com.evil.test', which
  // stays null even against a naive `bare.includes(appDomain)` mutation of
  // the suffix check (it ends in '.evil.test', not '.salonhub.com' under
  // any reading). 'salonhub.com.info' is the host that actually catches
  // that mutation: substring-matching the apex anywhere in the string lets
  // a lookalike TLD slip a slug ('salo') out of the middle of the string.
  // See the mutation matrix in task-15-report.md for the verified proof.
  it('refuses a lookalike domain that merely contains the apex', () => {
    expect(resolveSlugFromHost('salonhub.com.info', 'salonhub.com')).toBeNull()
    expect(resolveSlugFromHost('salonhub.com.evil.test', 'salonhub.com')).toBeNull()
  })

  it('refuses a multi-label subdomain', () => {
    expect(resolveSlugFromHost('a.b.salonhub.com', 'salonhub.com')).toBeNull()
  })

  it('ignores a port and is case-insensitive', () => {
    expect(resolveSlugFromHost('Beauty-Queen.SalonHub.com:8443', 'salonhub.com')).toBe(
      'beauty-queen',
    )
  })

  describe('default host argument', () => {
    const originalLocation = window.location

    afterEach(() => {
      Object.defineProperty(window, 'location', {
        value: originalLocation,
        writable: true,
        configurable: true,
      })
    })

    // This is how the app actually calls it: `resolveSlugFromHost()` with no
    // arguments, reading the real browser host.
    it('falls back to window.location.hostname when no host is given', () => {
      Object.defineProperty(window, 'location', {
        value: { ...originalLocation, hostname: 'beauty-queen.salonhub.com' },
        writable: true,
        configurable: true,
      })

      expect(resolveSlugFromHost(undefined, 'salonhub.com')).toBe('beauty-queen')
    })
  })
})

describe('publicApiBase', () => {
  it('is path-scoped when the slug came from the URL', () => {
    expect(publicApiBase('beauty-queen')).toBe('/public/beauty-queen')
  })

  // Task 12's review finding: GET /api/public/{org} is two segments and was
  // being shadowed by the host-resolved group, so a salon slugged "site"
  // served another salon's payload. The fix gives the host group its own
  // 'public-site/' prefix rather than a bare '/public' with no slug.
  it('is host-scoped when there is no slug in the URL', () => {
    expect(publicApiBase(undefined)).toBe('/public-site')
  })
})
