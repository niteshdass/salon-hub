// The apex salons get a subdomain under. Mirrors backend config('app.domain'),
// which reads the same APP_DOMAIN env var with the same default — CORS
// (backend/config/cors.php) is anchored to it too, so all three agree on
// which hosts are ours.
export const APP_DOMAIN = import.meta.env.VITE_APP_DOMAIN || 'salonhub.com'

// Hosts that are the product itself, never a salon.
const RESERVED = new Set(['app', 'www', 'api', 'admin', 'mail', 'static'])

/**
 * Map a Host header to a salon slug, or null when the host is the marketing
 * site, the dashboard, or anything outside our apex.
 *
 * This only decides what the browser renders. The server is the authority on
 * which tenant's data is served (Domain::resolveOrganizationForHost), so a
 * wrong answer here is a wrong page, never another salon's data. It is still
 * written to match the server's normalisation: lowercased, port stripped, and
 * the trailing root dot in `beauty-queen.salonhub.com.` removed, so all three
 * spellings of one name render one salon.
 *
 * Exact suffix match only: `salonhub.com.evil.test` must not resolve.
 */
export function resolveSlugFromHost(host = window.location.hostname, appDomain = APP_DOMAIN) {
  const bare = String(host ?? '')
    .split(':')[0]
    .toLowerCase()
    .replace(/\.+$/, '')

  if (bare === appDomain) return null
  if (!bare.endsWith(`.${appDomain}`)) return null

  const label = bare.slice(0, -(appDomain.length + 1))

  // Only a single label is a salon: `a.b.salonhub.com` is not.
  if (label.includes('.')) return null
  if (RESERVED.has(label)) return null
  if (!/^[a-z0-9-]+$/.test(label)) return null

  return label
}

/**
 * Base path for public API calls: path-scoped when a slug came from the URL,
 * host-scoped when we are on the salon's own subdomain.
 *
 * Takes the ROUTE's slug, not a resolved one — the choice is about which
 * shape of URL the page was reached through, and the host-scoped endpoints
 * let the server read the tenant from the Host header itself.
 */
export function publicApiBase(routeSlug) {
  return routeSlug ? `/public/${routeSlug}` : '/public'
}
