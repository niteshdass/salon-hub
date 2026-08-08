# SalonHub Marketing / Landing Site — Design

**Date:** 2026-07-27
**Status:** Approved (design), pending implementation plan

## Goal

Give the SalonHub SaaS a public marketing home page at `/` that sells the
product to prospective salon owners, drives them to **Register a salon** or
**Login**, presents **pricing**, and — after they authenticate — lands them on
their admin dashboard with their booking-site subdomain surfaced as a
shareable link.

## Audience & positioning

This is the **B2B platform brand** ("SalonHub") selling to salon owners. It is
distinct from the per-salon public microsite (`/salon/{slug}`), which uses a
warm stone/editorial palette aimed at that salon's own customers.

## Visual direction

**Modern SaaS with a warm accent.** Light theme, generous whitespace, crisp
type, soft shadows, rounded cards — but carrying a warm beauty-industry accent
color (terracotta / rose family) so it reads as premium-beauty, not
generic-tech. Distinctive display font paired with a clean body font (avoid
Inter/Arial/Roboto defaults). Cohesive, intentional, not "AI slop."

## Scope

In scope:
- Public marketing landing page at `/` (nav, hero, features, how-it-works,
  pricing, testimonials, FAQ, contact, footer).
- Post-auth subdomain link surfaced on the staff dashboard.
- A public contact endpoint (the only backend work).

Out of scope (explicitly deferred):
- Real subscription billing / checkout / plan enforcement (pricing is static
  marketing content).
- Wildcard DNS / true subdomain-served admin (tenancy stays token-based;
  subdomains are surfaced as links only).
- Any change to the existing `/register`, `/login`, or `/salon/{slug}` views
  beyond routing wiring.

## Architecture

### Routing (frontend — Vue 3 SPA, existing)

Today the authenticated shell (`DashboardLayout`) is mounted at `path: '/'`
with an index child `{ path: '', redirect: '/dashboard' }`, so bare `/`
redirects into the admin. That changes:

- Add a **public** top-level route, declared **before** the `DashboardLayout`
  record: `{ path: '/', name: 'landing', component: LandingView }`.
- Remove the `{ path: '', redirect: '/dashboard' }` index child from
  `DashboardLayout` so the shell no longer claims bare `/`. Its other children
  (`dashboard`, `appointments`, …) keep their own `meta: { requiresAuth: true }`
  and resolve unchanged (`/dashboard`, etc.).
- Vue Router matches routes in declaration order; the landing leaf is declared
  first and fully matches `/`, so `/` renders the landing page while
  `/dashboard` and siblings continue to resolve inside `DashboardLayout`.
- Guard addition in `router.beforeEach`: if `to.name === 'landing'` and staff
  is authenticated, redirect to `/dashboard` (owners skip marketing). Anonymous
  visitors see the landing page.

**Verification requirement:** the implementer must confirm during build/manual
test that (a) `/` renders `LandingView` for an anonymous visitor, (b) `/`
redirects an authenticated owner to `/dashboard`, and (c) every existing staff
route (`/dashboard`, `/appointments`, …) still resolves. This is the one
non-obvious routing risk.

CTA targets:
- **Register a salon** → `/register` (existing view).
- **Login** → `/login` (existing view).
- **Pricing / Features / FAQ / Contact** → in-page anchor scroll.

### Post-auth subdomain link (frontend)

Registration already creates a primary `Domain` row `{slug}.salonhub.com`
(unverified) via `App\Actions\Auth\RegisterOrganization`. `RegisterView`
already redirects to `/dashboard` on success, so no redirect change is needed —
the work is surfacing the subdomain.

On the staff dashboard, add a `SubdomainBanner` component: **"Your booking site
is live"**, showing the salon's primary domain string with **Copy** and
**Visit** actions.
- The domain string shown is always `{slug}.salonhub.com` (the shareable URL).
- **Visit** in production → `https://{slug}.salonhub.com`.
- **Visit** in local dev (host is `localhost`, real subdomain unresolvable) →
  `/salon/{slug}` (the same microsite via path-based routing).
- Environment discriminator: use the frontend build env (e.g.
  `import.meta.env.PROD`) to choose the Visit target; the displayed string
  stays `{slug}.salonhub.com` in both.
- Data source: the primary `Domain` is already returned on `login` / `me`
  (`organization.domains`); the banner reads the `is_primary` domain, falling
  back to deriving `{slug}.salonhub.com` from `organization.slug` if the
  domains array is absent.

### Contact endpoint (backend — the only backend work)

`POST /api/contact` — **public** (no auth, **not** tenant-scoped, registered
outside both the auth and tenant middleware groups, alongside the other
platform-level public routes).

- Rate-limited to guard against spam (e.g. `throttle:5,1` — 5/min per IP).
- Validates `name` (required, string, max 255), `email` (required, email),
  `message` (required, string, max 5000) via a `StoreContactRequest` FormRequest.
- Sends a `ContactMessageMail` Mailable to the platform contact address, read
  from config (`config('mail.contact_address')`, backed by a `CONTACT_EMAIL`
  env var, default `wpulse2024@gmail.com`). In local dev `MAIL_MAILER=log`, so it
  lands in `storage/logs/laravel.log`.
- Returns `200` with a generic success message on success, `422` on validation
  failure, `429` when throttled.

New backend files:
- `app/Http/Controllers/ContactController.php`
- `app/Http/Requests/Contact/StoreContactRequest.php`
- `app/Mail/ContactMessageMail.php`
- `resources/views/mail/contact/message.blade.php`
- Route line in `routes/api.php` (public, throttled).

## Marketing page composition

`LandingView.vue` composes focused section components under
`src/components/marketing/`:

1. **MarketingNav** — sticky top bar: logo/wordmark, anchor links (Features,
   Pricing, FAQ, Contact), **Login** (text), **Register a salon** (primary
   button). Mobile: collapses to a simple menu.
2. **Hero** — headline, sub-copy, two CTAs (Register a salon / Login), a
   supporting visual (illustration or product mock — CSS/SVG, no external
   assets).
3. **Features** — grid of ~4–6 product highlights (online booking, reminders,
   payments/deposits, reviews, multi-branch, your-own-site). Icon + title +
   one line each.
4. **HowItWorks** — 3 steps: Register your salon → Set up services & staff →
   Share your booking link.
5. **PricingSection** — 3 static tiers (below). Each card: name, price,
   feature list, **Get started** → `/register`. Middle tier visually
   highlighted.
6. **Testimonials** — 3 static quote cards (placeholder social proof, editable).
7. **FaqSection** — accordion; local open/close state; ~5 static Q&As.
8. **ContactSection** — contact form (name / email / message) posting to
   `POST /api/contact`, with inline success/error state; beside it a plain
   "or email us at wpulse2024@gmail.com" mailto link.
9. **MarketingFooter** — wordmark, nav links, copyright, social/mailto.

Dashboard: `SubdomainBanner.vue` (in `src/components/`).

Each component is small, single-responsibility, independently understandable.

## Pricing content (static, illustrative — editable later)

| Tier | Price | Includes |
|---|---|---|
| **Free** | $0 | 1 branch · online booking page · email notifications · up to 50 bookings/mo |
| **Starter** | $19/mo | Everything in Free · SMS/WhatsApp reminders · payment deposits · unlimited bookings · customer reviews |
| **Business** | $49/mo | Everything in Starter · multi-branch · staff management · custom domain · priority support |

Tier names align with the existing `SubscriptionPlan` enum (`free`, `starter`,
`business`). Prices/features are marketing placeholders and carry no billing
behavior. All **Get started** buttons route to `/register`.

## Contact form / mailto reconciliation

The user selected both "contact via mailto" and "contact form (needs backend)".
Resolution: build the real backend-backed **form** as the primary contact
method, and render a plain **mailto** link beside it as a lightweight fallback
("or email us directly"). Both selections satisfied.

## Data flow

- **Landing render:** static; no API calls except the contact form submit.
- **Contact submit:** browser → `POST /api/contact` → `StoreContactRequest`
  validation → `ContactMessageMail` to platform address → generic 200.
- **Subdomain banner:** reads already-loaded `organization` from the staff auth
  store (no new fetch); computes the Visit URL from build env + slug.

## Error handling

- Contact form: 422 → show field errors inline; 429 → "Too many messages, try
  again shortly."; network/500 → generic "Could not send, try again." The
  mailto fallback always works regardless of endpoint state.
- Subdomain banner: if `organization` or slug is somehow unavailable, hide the
  banner rather than render a broken link.

## Testing

- **Backend (TDD):** feature tests for `POST /api/contact`:
  - valid payload → 200 and `ContactMessageMail` sent to the configured address
    (`Mail::fake()` + `assertSent`);
  - missing/invalid fields → 422;
  - throttle trips after the limit (→ 429).
- **Frontend:** clean `npm run build` with all new chunks emitted (matches the
  existing frontend convention of build-as-gate); manual browser smoke of `/`
  (anonymous sees landing, owner redirects to dashboard) and the dashboard
  subdomain banner (Copy + Visit targets correct for dev).

## Global constraints

- No secrets in source; `CONTACT_EMAIL` via env/config, not hardcoded beyond
  the safe default.
- Contact endpoint must be rate-limited (spam guard) and must not be
  tenant-scoped.
- Do not regress existing staff routes or the customer-account routes when
  restructuring the router.
- Frontend aesthetic: no generic AI-slop defaults (no Inter/Arial/Roboto,
  no purple-on-white); commit to the modern-SaaS-warm-accent direction.
- Self-contained frontend assets (no external CDN fonts/images that would break
  offline/CSP); embed or self-host fonts and use CSS/SVG for illustration.
