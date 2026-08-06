# Onboarding Wizard — Design

**Date:** 2026-08-06
**Status:** Approved, ready for planning

## Problem

Registration creates an organization, an owner, a primary domain, a settings row, and one
branch with default Mon–Sat 09:00–18:00 hours. It does not create a single service or a
single staff member, and the branch has no address. The salon is therefore not bookable:
`SlotGenerator` needs a service duration and a staff member to produce any slot at all.

A salon owner who signs up today lands on a dashboard of zeroes with a sidebar of nine
sections and no indication of which three of them stand between them and a working booking
page. Most real users are not technical — they are running a salon, not evaluating
software — and the current landing state asks them to infer the setup order themselves.

This design adds a guided setup that takes an owner from first login to a shareable,
working booking link in about three minutes.

## Approach

The wizard is a frontend route driving the **existing** entity endpoints. Progress is
**derived from real rows**, never from a stored step counter.

The alternative — an `onboarding_progress` table holding draft payloads committed at the
end — was rejected. It duplicates every entity's validation and its drafts can disagree
with the rows the booking engine actually reads. A localStorage-only version was rejected
for the same reason plus loss of progress across devices.

Because progress is derived, a half-finished wizard leaves real, valid records behind. An
owner who quits after adding services has genuinely added services; the checklist reports
what is true rather than what was clicked.

## Flow and gating

**Route:** `/onboarding`, owner-only, rendered in its own minimal layout. No dashboard
sidebar — the sidebar is noise for someone who has not set anything up yet. Managers and
staff never see the wizard; they join an already-configured salon.

**Redirect rule** in `router.beforeEach`: when the target route has `requiresAuth`, the
role is `owner`, and `organization.onboarding_completed_at` is null, redirect to
`/onboarding`. `onboarding_completed_at` rides on `/auth/me`, which the guard already
awaits, so this costs no extra request.

**Soft gate.** Every screen carries "Skip for now", which saves nothing and lands on
`/dashboard`. On the dashboard a **Finish setup** card sits above the tiles showing the
checklist ("2 of 4 done") and resumes the wizard at the first incomplete step. The card
disappears when setup completes or the owner taps "Don't show again".

**Completion.** `onboarding_completed_at` is stamped when the owner reaches the final
screen's "Go to dashboard", or dismisses the dashboard card. Stamped means the wizard
never auto-opens again and the card is gone. `/onboarding` stays reachable by URL for an
owner who wants to walk it again; re-walking it does not clear the timestamp.

**Resume.** The wizard opens at the first incomplete step, computed from
`GET /onboarding/status`. Nothing already entered is asked for twice.

**Step completion is derived:**

| Step | Done when | Blocks Finish |
|---|---|---|
| Branch | `branches.address` non-empty for the org's branch | yes |
| Services | `services` count > 0 | yes |
| Staff | `users` with role `staff` count > 0 | yes |
| Look | `settings.about` non-empty **or** `organizations.logo` set | no |

"Blocks Finish" governs the final screen only. Every step remains individually skippable
mid-wizard; skipping a blocking step lands on the dashboard with the card showing, not on
the success screen.

## Screens

Mobile-first throughout: single column, large tap targets, sticky bottom primary button,
a "Step 2 of 4" progress indicator, and a working back arrow on every screen.

### 1. Where is your salon?

Opens with one framing line: "Four quick steps. About 3 minutes."

Name and phone are prefilled from registration. Fields: address, city, phone, and an
optional Google Maps link with the hint "paste the link from Google Maps".

Hours follow as seven day rows — a toggle plus open/close time selects — prefilled Mon–Sat
09:00–18:00 with Sunday off, matching `RegisterOrganization::DEFAULT_OPENING_HOURS`. A
"same time every day" shortcut copies the first row down.

Saves through the existing `PUT /branches/{id}`.

### 2. What do you offer?

The screen asks the salon type first — Hair salon, Beauty parlour, Barber, Spa, Nails —
as five large chips. Choosing one reveals its preset services, all pre-ticked, each row
carrying a name, a prefilled editable duration, and an **empty price field**.

Price is the one value the system cannot guess, so it is the only required typing on the
screen. Continue stays disabled until every ticked row has a price, with the reason stated
inline rather than as a silent disabled button. "+ Add your own" appends a blank row.

Saves through a new `POST /services/bulk`.

### 3. Who works here?

Two large cards, because the solo owner is the common case and deserves one tap:

- **I work alone** — creates one staff record bearing the owner's name, assigns every
  service just created, copies working hours from the branch, and advances.
- **I have a team** — rows of name, phone, optional email, and per-person service ticks.
  The owner appears as the first row, prefilled. The email field is labelled "only if they
  should log in".

The free plan caps staff at 10 (`PlanLimit::FREE_MAX_STAFF`). Row 11 shows the plan
message inline as the row is added, rather than failing the whole save afterwards.

**Why the solo path creates a row rather than reusing the owner's.** `StaffController`
defines staff as `User(role=staff)` plus a `StaffProfile`, and scopes every query — index,
appointment assignment, slot generation — to `role=staff`. An owner is `role=owner`, so
hanging a profile on their existing row would leave them invisible to booking. The solo
path therefore posts a normal staff record carrying the owner's name, with no email, which
takes the synthetic `.invalid` address described below (the owner's real address is already
on their owner row and is unique). The owner then appears once under Staff as a bookable
person and signs in with their separate owner account.

Broadening the definition of staff to include owners who hold a profile was rejected: it
changes `StaffController::baseQuery`, appointment validation and `SlotGenerator` together,
for a case one extra row already covers.

### 4. Make it yours *(optional)*

Logo, cover photo, about text, theme colour swatches, with a live preview strip of the
salon page beside the fields (stacked below on mobile). Uses the existing
`settings/organization` endpoints and their logo/cover upload routes. Skipping never
blocks Finish — the public page renders with defaults.

### 5. You're live

The payoff screen, and the moment a non-technical owner starts believing the product
works:

- the booking URL, large, with a copy button
- share buttons for WhatsApp and Facebook
- **Download QR poster** — a PNG carrying the salon name and a QR code for the booking
  URL, sized for a shop wall
- "Try booking yourself", opening `/book/:slug` in a new tab
- "Go to dashboard", which stamps `onboarding_completed_at`

## Backend

Screens 1 and 4 need no new endpoints. New work:

**Migration** — `organizations.onboarding_completed_at`, nullable timestamp, cast to
`datetime`, exposed on `OrganizationResource`.

**`GET /onboarding/status`** — owner-only, tenant-scoped. Returns `completed_at`, a `done`
boolean per step derived by counting rows, the org's `branch_id`, and `next_step`. Nothing
is cached or stored.

**`POST /onboarding/complete`** — stamps the timestamp. Idempotent: a repeat call returns
200 and leaves the original timestamp alone.

**`GET /service-presets`** — serves `config/service_presets.php`. The list lives in config
rather than as a frontend constant so durations and wording can change without rebuilding
the SPA.

**`POST /services/bulk`** — accepts an array of rows plus the chosen salon type, inside one
transaction. Creates a single `service_category` named for the salon type and hangs the
services off it, which gives the public salon page its grouping for free. Validation
errors are keyed per row index (`rows.2.price`) so the wizard can highlight the offending
line. Tenant scope comes from the existing `tenant` middleware.

**Staff without an email.** `users.email` is `NOT NULL UNIQUE`, and every staff member is a
`users` row, so a team member known only by name and phone cannot be saved today.
`StoreStaffRequest.email` becomes `nullable`, and `StaffController` mints
`staff-{token}@{slug}.invalid` when it is absent. `.invalid` is reserved by RFC 2606 and
is guaranteed undeliverable, so a synthetic address can never send mail to a real stranger
who happens to own that domain. The password stays the existing `Str::password(12)` random
value and no verification mail is sent, so the row cannot be used to sign in. Making
`users.email` nullable instead was rejected: it touches login lookup, password reset,
notifications, and the existing unique index, for a case that only arises here.

The plan limit is checked per row before the transaction opens, so a free-plan owner
adding an eleventh member gets a message rather than a partial save.

## Service presets

`config/service_presets.php`, keyed by salon type. Durations in minutes, no prices — those
vary by country and currency and are always typed by the owner.

- **Hair salon** — Hair cut 30, Hair wash & blow dry 30, Hair colour 90, Highlights 120,
  Hair spa 60, Straightening 120, Trim 20, Kids cut 20
- **Beauty parlour** — Facial 60, Threading 15, Waxing (full arms) 30, Waxing (full legs)
  45, Manicure 45, Pedicure 45, Bridal makeup 120, Party makeup 60
- **Barber** — Hair cut 30, Beard trim 15, Shave 20, Hair cut & beard 45, Head massage 20,
  Hair colour 45, Kids cut 20, Face cleanup 30
- **Spa** — Full body massage 60, Head & shoulder massage 30, Aroma therapy 90, Body scrub
  60, Foot massage 30, Steam & sauna 45, Couple massage 90, Back massage 30
- **Nails** — Manicure 45, Pedicure 45, Gel polish 60, Nail extension 90, Nail art 45,
  Polish change 20, Nail repair 30, French manicure 60

## Testing

**Backend feature tests**

- step derivation returns the right `done` set for each state: fresh org, branch addressed,
  services added, staff added, fully set up
- `complete` is idempotent and does not move an existing timestamp
- `bulk` rejects a row with no price, keys the error by row index, and writes nothing on
  failure
- `bulk` creates exactly one category per call, and a second call naming the same salon
  type reuses that category rather than duplicating it
- `bulk` and both onboarding routes reject a caller from another tenant
- the solo path creates exactly one `role=staff` row and leaves the owner's own user row
  untouched
- staff created with no email gets a unique `.invalid` address, and two such rows in the
  same organization do not collide
- staff creation stops at `FREE_MAX_STAFF` with the plan message
- non-owner roles get 403 from both onboarding routes

**Frontend tests**

- router guard: incomplete owner → `/onboarding`; manager and staff → dashboard;
  completed owner → dashboard; `/onboarding` still reachable by URL after completion
- wizard resumes at the first incomplete step
- services screen keeps Continue disabled while a ticked row has no price

## New dependency

`qrcode` (~15KB) in the frontend, for the QR poster on screen 5. The only addition in this
design.

## Out of scope

Interface translation. Presets, labels and hints are English for this release; localisation
is a separate piece of work with its own spec.
