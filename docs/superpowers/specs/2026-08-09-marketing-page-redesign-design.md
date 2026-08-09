# Marketing page redesign

**Date:** 2026-08-09
**Status:** approved
**Scope:** `frontend/src/views/LandingView.vue` and `frontend/src/components/marketing/*`

## Problem

The landing page fails at four things at once:

1. **Generic.** A hero, a six-card icon grid, three testimonials, an FAQ — the
   shape of every SaaS page. Nothing about it says salon.
2. **Doesn't convert.** No single repeated call to action, proof arrives late,
   and the page never names the problem it solves.
3. **Wrong audience.** Written for a generic English-speaking salon. The actual
   buyer is a small salon or parlour owner in Bangladesh who takes bookings over
   Messenger and WhatsApp today.
4. **Weak on mobile.** That buyer arrives on a phone, and the hero's floating
   cards overflow the viewport there.

## Decisions taken

| Question | Decision |
| --- | --- |
| Visual direction | Keep the editorial look — Fraunces display, Manrope body, `paper` cream, `brand-*` terracotta. Sharpen it; do not repaint. |
| Audience | Small salons and parlours in Bangladesh, phone-first. |
| Language | English only, locally grounded. No i18n machinery. |
| Proof | Nothing is real — the product is pre-launch. Remove all invented proof. |
| Audience split | Owner-only sales page. Customer links (`Find a salon`, `My bookings`) demoted to small nav and footer links. |
| Brand name | Glowhub, replacing SalonHub, on this page only. |

Audience fit comes from copy, pricing and structure — not from restyling. The
page must not start looking like a utility app.

## The new page

Read top to bottom, the page is an argument: name the pain, show what the fix
looks like, explain how to start, state the cost, address "why trust something
new", clear the objections, then ask for the signup.

### 1. Nav — rewritten

Glowhub wordmark. Anchors: Features, How it works, Pricing, FAQ. `Find a salon`
and `Salon log in` as small text links. `Register free` as the only button, and
it stays visible in the bar at every width. The existing mobile sheet is kept.

### 2. Hero — rewritten

- Eyebrow: `Booking software for salons`
- Headline: **"Your next client is trying to book at 11pm."**
- Body: Glowhub gives your salon its own booking page — it takes appointments
  while you're closed, sends the reminder, and holds the slot with an advance.
  No more scrolling back through Messenger to find who's coming at 4.
- Buttons: `Register free →` (primary), `See a live booking page` (secondary,
  → the `demo-salon` tenant).
- Reassurance: `Free forever · No card · Live in 10 minutes`
- Visual: the existing CSS day-schedule mock, extracted to a component, with ৳
  amounts and a closing line — *booked at 11:04pm, while you slept*.

### 3. Pain band — new

A rule-list, the same bordered-row device the page already uses for features:

> **Today** — A client messages at midnight. You reply at 9am. She's booked somewhere else.
> **Today** — Three "confirmed" appointments, one shows up. Nobody was reminded.
> **Today** — Someone asks the price of highlights. You type it out. Again.
> **Glowhub** — She books herself, gets a reminder the day before, and the price was on the page.

### 4. Product tour — new, replaces the feature grid

Three blocks, each with a CSS mock of real product UI, alternating sides at `lg`:

1. **A booking page of your own** — `glowhub.com/your-salon`, live the minute you
   register. Services, prices, stylists, photos. Share the link in your bio.
2. **Reminders that get read** — automatic SMS or WhatsApp the day before, from
   your own number. The single biggest thing you can do about no-shows.
3. **Money you can see** — take an advance at booking by card or mobile banking,
   and see what the week made: bookings, revenue, no-shows, staff.

Everything else collapses into one "Also included" rule line: staff schedules and
time off, reviews from real visits, calendar, customer list, expenses and payroll.

Every claim here is backed by shipped code — `AppointmentReminderService` and
`SendAppointmentReminder` (Twilio SMS + WhatsApp), `SslcommerzGateway`,
`ReportService`, `Review`, `PayrollRun`.

### 5. How it works — kept, tightened

Three steps with time estimates: register (2 min) → add services and staff
(5 min, guided) → share your link.

### 6. Pricing — rewritten

**৳0. Everything above.** One branch, 10 staff, unlimited services, unlimited
clients. Not a trial — the free plan is the product. Paid plans arrive when
salons need more branches, and salons hear about it first.

Footnote: card and mobile-banking advances run through SSLCommerz; SMS and
WhatsApp reminders use the salon's own Twilio account, billed by Twilio.

### 7. Trust band — new, replaces testimonials

> **We're new. Here's what that means.**
> - No fake reviews here. We won't show you testimonials we don't have.
> - Built in Bangladesh, for salons here — ৳, local payments, the way clients actually message you.
> - Your client list is yours. Export it any time. Leave any time.
> - You can reach a human. Reply to any email and a founder answers.

Plus `See a real booking page →`.

### 8. FAQ — kept, re-aimed at objections

My clients only use Messenger, will they use this? · Do I need a website
already? · Do I need a card to sign up? · Can I take an advance payment? · What
happens when it stops being free? · Who owns my client list? · Can I run more
than one branch?

### 9. Final CTA — new

Dark `ink` band. "Your booking page is ten minutes away." Free to start. No card.
Nothing to install. One button.

### 10. Footer — absorbs contact

Wordmark, one line of description, support email and WhatsApp, a two-field
message form (name + message), and the link columns. The full-height contact
section goes away.

### Removed

The six-card feature grid, the three invented testimonials, the
`43% / 10 min / 24/7 / $0` stat band, and the standalone contact section. The
stat band goes because none of those numbers can be substantiated pre-launch.

## Component architecture

New, in `frontend/src/components/marketing/`:

| File | Purpose |
| --- | --- |
| `PainSection.vue` | the Today/Glowhub rule-list |
| `ProductTourSection.vue` | the three alternating blocks |
| `TrustSection.vue` | the pre-launch honesty band |
| `CtaSection.vue` | the dark closing band |
| `SectionHeading.vue` | eyebrow + serif heading + lede, hand-rolled in every section today |
| `RuleList.vue` | the bordered-row device, used by the pain band and "Also included" |
| `MarketingCta.vue` | the button pair, currently duplicated across hero, pricing, nav and footer |
| `mocks/BookingDayMock.vue` | extracted from the hero, which is 161 lines and mostly mock markup |
| `mocks/SalonPageMock.vue` | tour block 1 |
| `mocks/RemindersMock.vue` | tour block 2 |
| `mocks/MoneyMock.vue` | tour block 3 |

Deleted: `TestimonialsSection.vue`, `FeaturesSection.vue`, `ContactSection.vue`.

Rewritten: `HeroSection.vue`, `PricingSection.vue`, `FaqSection.vue`,
`MarketingNav.vue`, `MarketingFooter.vue`. Copy-only: `HowItWorksSection.vue`.

`LandingView.vue` composes the new order: Nav, Hero, Pain, ProductTour,
HowItWorks, Pricing, Trust, Faq, Cta, Footer.

Two constraints on the implementation:

- **Copy stays inline** in each SFC as `script setup` constants. One locale and
  one consumer — a central copy module would be indirection for nothing.
- **Mocks are CSS and inline SVG, no image assets.** Matches the current page and
  keeps the page fast on a cheap Android.

Each mock component owns one visual and nothing else, so a tour block reads as
heading, body, and a named mock rather than eighty lines of nested divs.

## Mobile

- Mobile-first, single column below `lg`. Page padding `px-5` on phone (down from
  `px-6`), `lg:px-8`.
- **Hero overflow fix.** The floating cards currently use `-top-5 -right-3` and
  `-bottom-6 -left-3`, which push past a narrow viewport. Below `sm` they render
  as rows inside the card instead, with no negative offsets.
- **Headline scale.** `text-5xl` wraps badly at 360px. Clamp to roughly 36px on
  phone rising to 60px at `lg`.
- **Tour blocks** alternate sides only at `lg`; on phone the order is always text
  then mock, so reading order never flips.
- **Tap targets** at least 44px. Current nav text links sit near 28px.
- **Sticky bottom CTA on phone** — a full-width `Register free` bar that appears
  once the hero scrolls out of view, hidden at `lg`.
- The existing `prefers-reduced-motion` guard extends to any new reveal.

## Testing

Vitest with `@vue/test-utils` is already configured.

`LandingView.spec.js`:

- renders the ten sections in the specified order
- every primary call to action targets `/register`
- the rendered page contains no `SalonHub` and no `$`
- the hero's phone branch and desktop branch both render their content, scoped
  the way `FinanceView.responsive.spec.js` scopes its `md:hidden` / `md:block`
  containers, since jsdom has no media queries
- the sticky mobile CTA exists and is hidden at `lg`

`MarketingNav.spec.js`:

- the mobile sheet opens and closes
- `Find a salon` and `Salon log in` are present as links, and `Register free` is
  the only button

No per-section copy assertions — they break on every wording change and prove
nothing.

## Dependencies and risks

**Dependency:** the `demo-salon` tenant from `DemoSalonSeeder` must exist in
production for `See a live booking page`. If it does not, that button links to
`/find` instead.

**Risks:**

- *The copy is untested.* No parlour owner has read "Your next client is trying
  to book at 11pm." The structure keeps the headline and each pain line to a
  one-line edit in a single component.
- *The honesty band is an unusual move.* It bets that candour beats invented
  social proof for a skeptical local buyer. It is also the only honest option
  while nothing is real.
- *The Twilio disclosure adds friction* and may cost signups. Discovering it
  after setup would be worse.

## Out of scope

Bangla translation, real photography and motion, a consumer marketing page for
`/find`, and any backend change.

`SalonHub` appears in 26 files outside `components/marketing/` — emails, app
chrome, legal pages, `CLAUDE.md`. Only strings the landing page renders are
renamed here. The repo-wide rename is a separate mechanical change; folding it
in would bury this diff.
