import { describe, it, expect } from 'vitest'
import { needsOnboarding } from './index'

const owner = (completedAt) => ({
  isAuthenticated: true,
  role: 'owner',
  organization: { onboarding_completed_at: completedAt },
})

const dashboard = { name: 'dashboard', path: '/dashboard', meta: { requiresAuth: true } }

describe('needsOnboarding', () => {
  it('sends an owner who has never finished setup to the wizard', () => {
    expect(needsOnboarding(owner(null), dashboard)).toBe(true)
  })

  it('leaves an owner who has finished alone', () => {
    expect(needsOnboarding(owner('2026-08-06T10:00:00+00:00'), dashboard)).toBe(false)
  })

  it('never diverts a manager or a staff member', () => {
    expect(needsOnboarding({ ...owner(null), role: 'manager' }, dashboard)).toBe(false)
    expect(needsOnboarding({ ...owner(null), role: 'staff' }, dashboard)).toBe(false)
  })

  it('does not divert the wizard route itself, or it would loop', () => {
    expect(needsOnboarding(owner(null), { name: 'onboarding', path: '/onboarding', meta: { requiresAuth: true } }))
      .toBe(false)
  })

  it('leaves public routes alone', () => {
    expect(needsOnboarding(owner(null), { name: 'salon-site', path: '/salon/alpha', meta: {} })).toBe(false)
  })

  it('waits until the organization has loaded rather than guessing', () => {
    expect(needsOnboarding({ isAuthenticated: true, role: 'owner', organization: null }, dashboard)).toBe(false)
  })
})
