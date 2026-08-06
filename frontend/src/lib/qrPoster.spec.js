import { describe, it, expect } from 'vitest'
import { bookingUrl } from './qrPoster'

describe('bookingUrl', () => {
  it('prefers the salon\'s own domain', () => {
    expect(bookingUrl({ slug: 'beautyqueen', primary_domain: 'beautyqueen.salonhub.com' }))
      .toBe('https://beautyqueen.salonhub.com')
  })

  it('falls back to the slug path when no domain has been minted', () => {
    expect(bookingUrl({ slug: 'beautyqueen', primary_domain: null }))
      .toBe(`${window.location.origin}/book/beautyqueen`)
  })

  it('returns an empty string when there is no organization at all', () => {
    expect(bookingUrl(null)).toBe('')
  })
})
