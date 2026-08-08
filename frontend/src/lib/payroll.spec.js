import { describe, it, expect } from 'vitest'
import { PAY_TYPES, showsSalary, showsRate, monthOptions, payTypeLabel } from './payroll'

describe('pay type fields', () => {
  it('offers the four rules the API accepts', () => {
    expect(PAY_TYPES.map((t) => t.value)).toEqual(['none', 'commission', 'salary', 'hybrid'])
  })

  it('shows a salary field for salary and hybrid only', () => {
    expect(showsSalary('salary')).toBe(true)
    expect(showsSalary('hybrid')).toBe(true)
    expect(showsSalary('commission')).toBe(false)
    expect(showsSalary('none')).toBe(false)
  })

  it('shows a rate field for commission and hybrid only', () => {
    expect(showsRate('commission')).toBe(true)
    expect(showsRate('hybrid')).toBe(true)
    expect(showsRate('salary')).toBe(false)
    expect(showsRate('none')).toBe(false)
  })

  it('labels an unknown pay type without throwing', () => {
    expect(payTypeLabel('salary')).toBe('Fixed salary')
    expect(payTypeLabel('nonsense')).toBe('—')
  })
})

describe('monthOptions', () => {
  it('lists the current month first, then earlier months', () => {
    const options = monthOptions(3, new Date(2026, 7, 8)) // Aug 8 2026

    expect(options).toEqual([
      { value: '2026-08-01', label: 'August 2026' },
      { value: '2026-07-01', label: 'July 2026' },
      { value: '2026-06-01', label: 'June 2026' },
    ])
  })

  it('crosses a year boundary', () => {
    const options = monthOptions(2, new Date(2026, 0, 15)) // Jan 15 2026

    expect(options[1]).toEqual({ value: '2025-12-01', label: 'December 2025' })
  })
})
