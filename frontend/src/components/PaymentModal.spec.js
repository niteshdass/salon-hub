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

  // PaymentController::store() caps neither amount nor tip_amount against
  // the remaining balance, so once a booking is settled the UI is the only
  // thing standing between a typed Amount and a negative balance_due. These
  // two cases must disagree with each other, or the guard isn't real.
  it('zero-locks a typed amount to a numeric 0 once the booking is settled', () => {
    const payload = buildPaymentPayload({ amount: '20', tip_amount: '', method: 'cash', reference: '' }, true)
    expect(payload.amount).toBe(0)
    expect(payload.amount).not.toBe('')
    expect(payload.amount).not.toBeNull()
    expect(Number.isNaN(payload.amount)).toBe(false)
  })

  it('leaves a typed amount untouched when the booking is not settled', () => {
    const payload = buildPaymentPayload({ amount: '20', tip_amount: '', method: 'cash', reference: '' }, false)
    expect(payload.amount).toBe(20)
  })

  it('still sends the tip when settled — only amount is clamped', () => {
    const payload = buildPaymentPayload({ amount: '20', tip_amount: '7.25', method: 'cash', reference: '' }, true)
    expect(payload).toEqual({ amount: 0, tip_amount: 7.25, method: 'cash', reference: null })
  })
})
