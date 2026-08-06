// The address published on the marketing and legal pages. Mirrors the
// backend's config('mail.contact_address'), which reads the same CONTACT_EMAIL
// env var with the same default.
//
// This is a role address on the product domain, not a personal mailbox, and
// that is deliberate: on the privacy page it is the contact for data-subject
// requests about third parties' names, phone numbers, emails and booking
// notes, and on the refund page it is the escalation point for money disputes.
// Those roles need retention, delegation and continuity that a personal
// account cannot offer.
//
// It must be a real, monitored mailbox before launch — publishing an address
// nobody reads is worse than publishing none.
export const CONTACT_EMAIL = import.meta.env.VITE_CONTACT_EMAIL || 'support@salonhub.com'
