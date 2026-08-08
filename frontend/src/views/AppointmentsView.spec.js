import { describe, it, expect } from 'vitest'
import { staffWhoCanDoAll } from './AppointmentsView.vue'

describe('staffWhoCanDoAll', () => {
  const staff = [
    { id: 1, name: 'Alex', services: [{ id: 10 }, { id: 11 }] },
    { id: 2, name: 'Sam', services: [{ id: 10 }] },
    { id: 3, name: 'Unassigned', services: [] },
  ]

  it('keeps only staff who cover every selected service', () => {
    expect(staffWhoCanDoAll(staff, [10, 11]).map((s) => s.name)).toEqual(['Alex'])
  })

  it('returns everyone when nothing is selected', () => {
    expect(staffWhoCanDoAll(staff, [])).toHaveLength(3)
  })
})
