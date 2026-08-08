import { describe, it, expect } from 'vitest'
import { buildPaymentPayload } from './PaymentModal.vue'

// The counter-payment payload is the only place in the product a tip is
// built, so its coercion rules (blank -> 0, string -> number, tip never
// folded into amount) are pinned directly rather than through a full mount.
describe('buildPaymentPayload', () => {
  it('sends 0, not an empty string or NaN, when the tip field is blank', () => {
    const payload = buildPaymentPayload({ amount: '20', tip_amount: '', method: 'cash', reference: '' })
    expect(payload.tip_amount).toBe(0)
    expect(Number.isNaN(payload.tip_amount)).toBe(false)
  })

  it('sends a typed tip as a number', () => {
    const payload = buildPaymentPayload({ amount: '20', tip_amount: '5.50', method: 'cash', reference: '' })
    expect(payload.tip_amount).toBe(5.5)
  })

  it('keeps the tip as its own key, never folded into amount', () => {
    const payload = buildPaymentPayload({ amount: '20', tip_amount: '5.50', method: 'card', reference: 'ref-1' })
    expect(payload).toEqual({ amount: 20, tip_amount: 5.5, method: 'card', reference: 'ref-1' })
  })

  it('also coerces a blank amount to 0, e.g. for a tip-only payment', () => {
    const payload = buildPaymentPayload({ amount: '', tip_amount: '5', method: 'cash', reference: '' })
    expect(payload.amount).toBe(0)
  })
})
