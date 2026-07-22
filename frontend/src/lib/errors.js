// Normalize an axios error into a { message, errors } shape.
// - Laravel validation errors arrive as 422 { message, errors: { field: [...] } }.
// - Everything else falls back to the response message or a generic string.
export function parseApiError(err, fallback = 'Something went wrong. Please try again.') {
  const res = err?.response
  if (res?.status === 422 && res.data) {
    return {
      message: res.data.message || '',
      errors: res.data.errors || {},
    }
  }
  return {
    message: res?.data?.message || fallback,
    errors: {},
  }
}

// True when a 422 message reads like a subscription/plan limit
// (e.g. "Your free plan allows only 1 branch.").
export function isPlanLimit(err) {
  const msg = err?.response?.data?.message || ''
  return /plan allows/i.test(msg)
}
