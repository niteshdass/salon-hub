# Admin Shell Restyle + Per-Salon Theme Color — Design

**Date:** 2026-08-08
**Status:** Approved, not yet implemented

## Problem

The admin dashboard looks like a different product from the rest of SalonHub.
`DashboardLayout.vue` renders a white sidebar with indigo accents on slate-50,
while the platform brand established in `main.css` — cream `paper`, near-black
`ink`, terracotta `brand-*`, Fraunces display / Manrope body — governs the
marketing site, the auth pages and the public salon microsites. Inside the
dashboard, each of the ~15 views hand-rolls its own slate/indigo utility
strings, so card radii, table headers, status badges and empty states drift
from page to page.

Separately, `settings.theme_color` already exists (migration default
`#6366f1`) and already drives the public booking pages, but the salon owner
never sees their own color while working — the tool they spend their day in
ignores the brand they chose.

## Decisions

**Scope is the authenticated owner surface**: the admin dashboard, the auth
pages, and the onboarding wizard. Marketing, the public salon microsite, the
public booking flow and the customer dashboard keep their current look — they
speak to a different audience and their editorial styling is deliberate.

**The theme color is an accent, not a skin.** It paints the active nav item,
primary buttons, links, focus rings, badges and chart series. The sidebar stays
`ink` and the page background stays `paper` regardless of the hue chosen. This
keeps every possible accent legible without a per-hue contrast audit, and keeps
the app recognizably SalonHub across tenants.

**One color per salon.** The admin reuses `settings.theme_color` rather than
introducing an `admin_theme_color`. A salon has one brand; a second field would
make the owner reason about a distinction that does not exist for them. The
cost is accepted: changing the accent also repaints the public booking pages.

**`#6366f1` is treated as "never chosen."** `SalonSiteView.vue` already uses
this sentinel; the admin follows it and renders the SalonHub terracotta
`#c65d3b` instead. No migration, no backfill — a stored value some owner may
have picked on purpose is not rewritten. The accepted trade: an owner who
genuinely wants that exact indigo must pick a neighbouring hex.

**Accent lives in its own token family**, not by overriding `brand-*` at
runtime. Marketing and the landing page share this SPA and use `brand-*`; a
runtime override would repaint them per-tenant, which the scope decision
forbids. Per-component inline `:style` was the other option and was rejected —
hover, focus and ring variants would each need hand-written CSS.

## Scope

In:

- New `accent-*` token family and `sh-*` component primitives in `main.css`
- `src/lib/theme.js` + `stores/theme.js` — normalize, derive foreground, apply
- `theme_color` added to `OrganizationResource`
- `DashboardLayout.vue` rewrite + new `PageHeader.vue`
- Swatch picker in `SalonProfileSettings.vue`, shared with `StepLook.vue`
- All admin views, shared dialogs, auth views, onboarding wizard onto `sh-*`

Out:

- Dark mode
- Per-user (rather than per-organization) theme preference
- Custom logo replacing the sidebar mark
- Restyling marketing, public salon site, public booking, customer dashboard
- Any change to how the public pages compute their own accent

## Architecture

### Tokens

`main.css` gains an accent family in `@theme`, seeded with the terracotta ramp
so the app renders correctly before any data loads:

```css
@theme {
  --color-accent:     #c65d3b;
  --color-accent-fg:  #ffffff;   /* text ON accent */
  --color-accent-50:  color-mix(in oklch, var(--color-accent) 8%,  white);
  --color-accent-100: color-mix(in oklch, var(--color-accent) 18%, white);
  --color-accent-200: color-mix(in oklch, var(--color-accent) 35%, white);
  --color-accent-500: var(--color-accent);
  --color-accent-600: color-mix(in oklch, var(--color-accent) 82%, black);
  --color-accent-700: color-mix(in oklch, var(--color-accent) 65%, black);
}
```

One hex yields the whole ramp: the shades are `color-mix` expressions over
`--color-accent`, so overriding that single custom property at runtime moves
every shade with it. No JS color math, no generated stylesheet.

### `src/lib/theme.js`

Pure functions, no Vue imports, unit-tested directly:

- `normalizeAccent(hex)` — returns a usable `#rrggbb`. Malformed or missing
  input and the `#6366f1` sentinel both resolve to `#c65d3b`.
- `accentForeground(hex)` — relative luminance ≥ 0.55 returns the `ink` hex,
  otherwise white. This is why no swatch needs to be forbidden: a pale accent
  simply flips button text to dark.
- `applyAccent(hex)` — sets `--color-accent` and `--color-accent-fg` on
  `document.documentElement`.
- `THEME_SWATCHES` — the eight curated hexes, exported so the settings picker
  and the onboarding step cannot drift apart.

### `stores/theme.js`

Holds the current hex; `setAccent(hex)` normalizes, applies, and mirrors to
`localStorage`. Wiring:

- Backend: `theme_color` is added to `OrganizationResource`, so it rides along
  on `/auth/login`, `/auth/me` and register. **Staff and managers therefore get
  the accent without touching the owner-only settings endpoints.**
- `authStore.login` / `fetchMe` push `organization.theme_color` into the store.
- `main.js` applies the `localStorage` value synchronously before mount, so a
  reload does not flash terracotta and then snap to the salon's color.
- Saving in Settings calls `setAccent` immediately — live preview, no reload.

### Shell — `DashboardLayout.vue`

Sidebar: fixed `w-64`, `bg-ink`, white logo tile in `accent-500`, nav grouped
under four uppercase micro-labels:

| Group    | Items                                       |
|----------|---------------------------------------------|
| OPERATE  | Dashboard, Appointments, Calendar           |
| BUSINESS | Branches, Services, Staff, Customers        |
| INSIGHT  | Reports, Finance, Reviews                   |
| PRESENCE | Gallery, Settings                           |

The existing per-item `roles` filter is unchanged; a group header renders only
when the current role can see at least one of its items, so a staff user never
sees an empty INSIGHT heading. Active item is a full-width `bg-accent-500`
pill with `accent-fg` text. The sidebar foot pins an organization card —
initials avatar, name, plan label, Logout — with `mt-auto`.

Content column: `bg-paper`, sticky top bar carrying a breadcrumb
(`Calendar | Saturday, Aug 8`), a Help link, and a page-supplied primary action.
The page title renders in `font-display` at `text-5xl` with a one-line subtitle
beneath it.

Each view renders its own `PageHeader.vue` (title, subtitle, action slot)
rather than the layout reading route meta or receiving a teleport. Views stay
self-contained and the header can vary per-state — "1 appointment in this view"
is derived data, not a static route label.

Mobile behavior is preserved: the sidebar slides over a backdrop below `lg`,
and the unverified-email banner keeps its slot above `<RouterView>`.

### Primitives

Component classes in `main.css` under `@layer components`, following the
existing `.auth-*` precedent:

- `.sh-card` — white surface, warm hairline border, `rounded-2xl`
- `.sh-btn` and `.sh-btn-primary` (accent fill, `accent-fg` text),
  `.sh-btn-ghost`, `.sh-btn-danger`
- `.sh-input`, `.sh-label`, `.sh-error`
- `.sh-table` — uppercase micro-caps header in `ink/60`, warm row dividers
- `.sh-badge` plus status modifiers for pending / confirmed / completed /
  cancelled / no-show, currently duplicated across Appointments, Calendar and
  the customer dashboard
- `.sh-empty` — the repeated "nothing here yet" block

The `auth-*` classes are redefined as thin aliases of their `sh-*` equivalents
rather than deleted, so the five auth views convert without a big-bang rewrite
and no auth page breaks between batches.

### Picker

In `SalonProfileSettings.vue`, replacing the bare hex input: eight round swatch
buttons — terracotta `#c65d3b` (default), rose `#be123c`, amber `#b45309`,
forest `#166534`, teal `#0f766e`, blue `#0369a1`, violet `#7c3aed`, slate
`#334155` — with the selected one ringed. Below them a "Custom" disclosure
holds the existing `<input type="color">` and hex text field, so free choice is
not lost. `StepLook.vue` drops its local `THEMES` array and imports
`THEME_SWATCHES`.

## Implementation Order

Each batch is independently shippable and leaves the suite green.

1. **Foundation** — `theme.js`, theme store, `accent-*` tokens, `sh-*`
   primitives, `theme_color` on `OrganizationResource`. Nothing visible changes
   yet; the accent is merely available.
2. **Shell** — `DashboardLayout.vue` rewrite, `PageHeader.vue`. This is the
   commit where the app changes look.
3. **Picker** — settings swatches, shared `THEME_SWATCHES`, `StepLook.vue`.
4. **Admin views**, three passes:
   a. Dashboard, Appointments, Calendar — plus the shared `Modal.vue`,
      `ConfirmDialog.vue`, `PaymentModal.vue`, `SetupChecklistCard.vue`, since
      every later view depends on them
   b. Branches, Services, Staff, Customers
   c. Reports, Finance, Reviews, Gallery, Settings
5. **Auth + onboarding** — five auth views and the wizard onto `sh-*`;
   `AuthLayout.vue` and `OnboardingLayout.vue` take the paper/ink treatment.

Each pass in step 4 is its own commit rather than one broad diff.

## Testing

House pattern: Vitest with `@vue/test-utils`, mocking only `@/lib/api`.

- `theme.spec.js` — sentinel maps to terracotta; malformed hex falls back;
  a light hex yields the `ink` foreground; a dark hex yields white;
  `applyAccent` writes both custom properties.
- `DashboardLayout.spec.js` — existing role-visibility assertions must pass
  through the regrouped nav unchanged, plus a new case: a group header is
  absent when the role sees none of that group's items.
- Settings picker spec — clicking a swatch updates the theme store and the
  saved payload carries that hex.
- The eight existing view specs assert behavior, not class names, and must stay
  green untouched. One breaking on markup is a signal the restyle changed
  structure further than it needed to.

## Risks

- `color-mix(in oklch, …)` requires a 2023-or-later browser. Acceptable for an
  admin panel; the public pages do not depend on it.
- Batch 4 touches a wide surface. Split commits keep each reviewable.
- An owner who wants exactly `#6366f1` cannot express it, because that value
  reads as "unchosen." Same trade the public microsite already makes.
- The accent is shared with the public booking pages by design; an owner
  changing it for the dashboard changes it for customers too. The picker sits
  in Salon profile settings, where that scope is evident.
