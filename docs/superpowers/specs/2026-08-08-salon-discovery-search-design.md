# Salon Discovery Search — Design

**Date:** 2026-08-08
**Status:** Approved, not yet implemented

## Problem

A customer who arrives at `salonhub.com` cannot find a salon. The home page sells
software to salon owners; nothing on the platform lists the salons using it. A
customer who loses a salon's link has no way back short of guessing a subdomain.

Today four organizations exist, all in Sylhet. That density does not support a
filterable marketplace: a directory that shows an empty shelf teaches the
customer not to return and teaches the owner that the platform sends nobody. So
this piece builds the smallest honest version — one search box over the salons
that can actually take a booking — shaped so filters, categories and a
consumer-first home page drop in later without rework.

## Decisions

Discovery is a **free-text search page at `/salons`**, not a filtered directory
and not a home-page rebuild. The industry pattern this follows is Fresha's:
it ran for years as SaaS-only (as Shedul) and added a marketplace after supply
existed, not before.

The eventual destination is a consumer-first `/` with the owner pitch moved to
`/for-salons` — the visitor the site actually receives is a customer hunting for
a salon. That flip is deliberately **not** part of this piece: the search page is
the expensive half and must work before `/` depends on it. Flipping afterwards is
routing plus navigation, one reversible commit.

## Scope

In:

- `GET /api/discover/salons` — cross-tenant, unauthenticated, paginated
- `/salons` search page in the SPA
- Entry points in the marketing navigation and footer

Out (later pieces, in rough order):

- City dropdown and service-category chips — one city and per-salon categories
  today make both look broken
- Geolocation / "near me"
- Owner opt-out toggle ("list my salon in search") — add when an owner asks;
  default-listed is right while listing is free traffic nobody has objected to
- Ranking by rating
- The `/` → consumer-first flip
- SSR/prerendering

### Known limitation: no SEO

The SPA renders client-side, so Google indexes neither `/salons` nor the salon
shopfronts. Real customers mostly arrive through search engines, so this caps how
much traffic discovery can ever deliver. Fixing it (SSR or prerender) is its own
project and belongs after supply density, not before — a well-indexed directory
of four salons still shows an empty shelf.

## API

```
GET /api/discover/salons?q=<text>&page=<n>     throttle:60,1
```

Rate limited to 60 requests per minute per IP — a debounced search box is chatty,
an unauthenticated cross-tenant endpoint should not be free to scrape.

Public, unauthenticated, **not tenant-scoped** — the point is to look across
organizations. It lives in its own route group, outside both `public/{org}`
(path-resolved tenant) and `public-site` (host-resolved tenant).

With no tenant bound, `BelongsToOrganization`'s global scope stays inert by
design, so these queries run unscoped without any `withoutGlobalScopes()` escape
hatch. That makes the controller the only thing standing between a cross-tenant
query and a leak, so it whitelists every field it returns and never serialises a
model directly.

Handled by `App\Http\Controllers\Public\DiscoveryController`. Image URLs and the
rating summary follow `Public\SiteController`'s existing shapes; the shared
helpers move somewhere both can use rather than being copied.

### Response

```json
{
  "data": [
    {
      "slug": "chastity-hyde",
      "name": "Chastity Hyde",
      "city": "Sylhet",
      "cover_image_url": "https://…",
      "logo_url": "https://…",
      "currency": "BDT",
      "price_from": "500.00",
      "rating": { "average": 4.6, "count": 12 },
      "services": ["Hair cut", "Hair spa", "Facial"]
    }
  ],
  "meta": { "total": 4, "page": 1, "per_page": 12 }
}
```

- `city` — the `city` of the salon's oldest branch (lowest `id`), null when unset
- `price_from` — `MIN(price)` over the salon's active services
- `rating` — `null` when the salon has fewer than 3 published reviews. A new
  salon showing a blank rating beside an established one reads as "bad", when it
  only means "new"
- `services` — up to 3 active service names, for scanning

## Eligibility

A salon appears only when **all** hold:

| Condition | Why |
| --- | --- |
| `status = active` | Suspended salons are not open |
| `onboarding_completed_at` is not null | Setup finished |
| ≥ 1 branch | Somewhere to go |
| ≥ 1 service with `status = active` | Something to book |

Together: the salon can actually take a booking. A half-configured salon in the
results costs a customer a click and a dead end.

This lives in one query scope so the list endpoint and any future detail
endpoint cannot drift apart.

## Search and ordering

`q` is optional, trimmed, capped at 80 characters. It matches case-insensitively
against any of:

- organization name
- organization slug
- branch city
- active service name

An empty `q` browses every eligible salon — the page is useful before the
customer types anything.

Order:

1. Name matches ahead of matches found only through a service or city — someone
   typing "chastity" wants that salon, not every salon offering a service with a
   similar name
2. Most recent booking activity, `MAX(appointments.created_at)` per organization
3. Name A–Z

Rating deliberately does not rank: at four salons with almost no reviews it
would present noise as judgement. Ordering is fully deterministic, so pagination
cannot repeat or skip a salon between pages.

Page size 12, offset pagination.

## Frontend

`/salons` → `SalonSearchView.vue`, wrapped in `MarketingNav` + `MarketingFooter`.

It wears the **marketing light theme** (`paper`/`ink`, brand-500), not the dark
brass skin. The dark room belongs to a salon's own shopfront and to the
customer's account area; this is a platform page. Reusing the marketing chrome
also means the session-aware nav link ("Manage bookings" / "Manage your salon")
works here with no extra work.

Behaviour:

- One search input, debounced 300 ms
- `q` mirrors into the URL (`/salons?q=hair`), so results are shareable, the back
  button behaves, and a reload keeps the query
- Cards in a responsive grid; the whole card links to `/salon/{slug}`

Four states, all designed:

| State | Treatment |
| --- | --- |
| Loading | Skeleton cards, no layout shift when results land |
| Results | Grid, plus a count |
| No match | "Nothing matches '<q>'" and a clear-search button |
| Nothing listed | Honest copy — SalonHub is new in this city — plus the owner CTA |

Entry points: "Find a salon" in `MarketingNav` (desktop and mobile) and in
`MarketingFooter`.

## Testing

Backend feature tests:

- Each eligibility exclusion, separately: inactive status, null
  `onboarding_completed_at`, no branch, no active service
- Matching by name, by slug, by branch city, by service name
- Ordering: recent booking activity ahead of quiet salons, A–Z tiebreak,
  name match ahead of service-only match
- Rating hidden at 2 published reviews, shown at 3
- Unpublished reviews never counted; inactive services never priced and never
  matched
- Pagination: page 2 continues rather than repeating

Frontend (vitest):

- Debounce and URL synchronisation, including reload with `?q=`
- Each of the four states renders
- Card links to the correct slug

## Success

A customer who lands on `salonhub.com/salons`, types part of a salon's name, a
city, or a service, sees the salons that can take their booking, and reaches the
right shopfront in one click.
