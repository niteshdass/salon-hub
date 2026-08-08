// Pay rules, mirrored from the backend PayType enum. Kept here rather than
// inline in a view because two screens need them: the staff form writes a
// rule, the payroll table reads one back.
export const PAY_TYPES = [
  { value: 'none', label: 'Not paid through SalonHub', hint: 'Skipped by payroll runs.' },
  { value: 'commission', label: 'Commission only', hint: 'A percentage of what they bill.' },
  { value: 'salary', label: 'Fixed salary', hint: 'The same amount every month.' },
  { value: 'hybrid', label: 'Salary + commission', hint: 'A monthly amount plus a percentage.' },
]

export function showsSalary(payType) {
  return payType === 'salary' || payType === 'hybrid'
}

export function showsRate(payType) {
  return payType === 'commission' || payType === 'hybrid'
}

export function payTypeLabel(payType) {
  return PAY_TYPES.find((type) => type.value === payType)?.label || '—'
}

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

/**
 * The last `count` months, newest first, as {value: 'YYYY-MM-01', label}.
 * `today` is injectable so the test does not depend on the wall clock.
 */
export function monthOptions(count, today = new Date()) {
  const options = []
  for (let i = 0; i < count; i += 1) {
    const date = new Date(today.getFullYear(), today.getMonth() - i, 1)
    const month = String(date.getMonth() + 1).padStart(2, '0')
    options.push({
      value: `${date.getFullYear()}-${month}-01`,
      label: `${MONTH_NAMES[date.getMonth()]} ${date.getFullYear()}`,
    })
  }
  return options
}
