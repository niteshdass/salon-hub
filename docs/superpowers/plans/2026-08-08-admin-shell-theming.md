# Admin Shell Restyle + Per-Salon Theme Color Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the authenticated owner surface (admin dashboard, auth pages, onboarding) onto the SalonHub paper/ink/Fraunces brand with a dark grouped sidebar, and let each salon's `theme_color` drive the accent across that surface.

**Architecture:** A new `accent-*` Tailwind token family whose shades are `color-mix` expressions over a single `--color-accent` custom property. A pure `lib/theme.js` normalizes the salon's hex, picks a legible foreground, and writes both properties onto `document.documentElement`; a Pinia store holds the value and mirrors it to `localStorage`. The hex reaches the client on `/auth/login` and `/auth/me` via `OrganizationResource`, so every role gets it without hitting owner-only settings endpoints. Views are rewired onto `sh-*` component classes declared once in `main.css`.

**Tech Stack:** Vue 3 (`<script setup>`), Pinia, Vue Router, Tailwind CSS v4 (`@theme` / `@layer components`, no config file), Vitest + `@vue/test-utils`, Laravel 12 + PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-08-admin-shell-theming-design.md`

## Global Constraints

- Scope is the authenticated owner surface only: admin dashboard, auth pages, onboarding wizard. **Do not touch** `LandingView.vue`, `SalonSiteView.vue`, `PublicBookingView.vue`, `ManageBookingView.vue`, `SalonSearchView.vue`, `CustomerDashboardView.vue`, `CustomerLoginView.vue`, `CustomerLayout.vue`, or anything under `components/marketing/` and `components/legal/`.
- The accent paints foreground elements only. Sidebar stays `ink` (`#241c18`), page background stays `paper` (`#faf6f1`), regardless of the chosen hue.
- `#6366f1` means "never chosen" and renders as the brand terracotta `#c65d3b`. No migration, no DB backfill, no change to the column default.
- Existing `brand-*` tokens are SalonHub's own identity and must keep their current values — the tenant accent is a separate family.
- Frontend tests: `cd frontend && npm run test:unit`. Backend tests: `cd backend && php artisan test`.
- Existing view specs assert behavior, not class names. They must stay green without edits. One breaking on markup means the restyle changed structure further than it needed to — fix the view, not the test.
- Every commit message is written normally (not terse), present-tense, and ends with:
  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  ```

### Class mapping (used by Tasks 8–11)

Apply this table when converting a view. Left column is what exists today; right column is the replacement.

| Existing markup | Replacement |
|---|---|
| `rounded-2xl border border-slate-200 bg-white p-6 shadow-sm` (card/section wrapper) | `sh-card p-6` |
| `rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700` | `sh-btn sh-btn-primary` |
| `rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm ... hover:bg-slate-50` | `sh-btn` |
| `text-indigo-600 hover:text-indigo-700` (text-only action) | `sh-btn-ghost` |
| `text-rose-600 hover:text-rose-700` / `bg-red-600` (destructive) | `sh-btn-danger` |
| `w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none` | `sh-input` |
| `mb-1 block text-sm font-medium text-slate-800` (field label) | `sh-label` |
| `mt-1 text-xs text-red-600` (field error) | `sh-error` |
| `text-slate-900` (heading/body strong) | `text-ink` |
| `text-slate-600` / `text-slate-500` (muted) | `text-ink/60` |
| `text-slate-400` (faint) | `text-ink/40` |
| `border-slate-200` (hairline) | `border-ink/10` |
| `bg-slate-50` (page or zebra background) | `bg-paper` |
| `bg-slate-100` (placeholder tile) | `bg-ink/5` |
| any remaining `indigo-*` | matching `accent-*` |
| `<h1 class="text-2xl font-semibold ...">` + sibling `<p>` at top of a view | `<PageHeader :title="…" :subtitle="…">` |
| hand-rolled status pill (`rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800` etc.) | `sh-badge sh-badge-<status>` |
| hand-rolled "nothing here yet" block | `sh-empty` |

---

## Task 1: Theme helpers (`lib/theme.js`)

**Files:**
- Create: `frontend/src/lib/theme.js`
- Test: `frontend/src/lib/theme.spec.js`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `BRAND_ACCENT: string` — `'#c65d3b'`
  - `INK: string` — `'#241c18'`
  - `ACCENT_STORAGE_KEY: string` — `'salonhub.accent'`
  - `THEME_SWATCHES: string[]` — eight lowercase hex strings
  - `normalizeAccent(value: unknown): string` — always a lowercase `#rrggbb`
  - `relativeLuminance(hex: string): number` — 0..1
  - `accentForeground(hex: string): string` — `INK` or `'#ffffff'`
  - `applyAccent(hex: unknown, root?: HTMLElement): string` — writes `--color-accent` and `--color-accent-fg`, returns the normalized hex

- [ ] **Step 1: Write the failing test**

Create `frontend/src/lib/theme.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import {
  BRAND_ACCENT,
  INK,
  THEME_SWATCHES,
  normalizeAccent,
  accentForeground,
  applyAccent,
} from '@/lib/theme'

describe('normalizeAccent', () => {
  it('keeps a salon colour the owner actually chose', () => {
    expect(normalizeAccent('#0F766E')).toBe('#0f766e')
  })

  it('accepts a hex without the leading hash', () => {
    expect(normalizeAccent('0f766e')).toBe('#0f766e')
  })

  it('reads the API default as "never chosen" and returns the brand terracotta', () => {
    expect(normalizeAccent('#6366f1')).toBe(BRAND_ACCENT)
    expect(normalizeAccent('#6366F1')).toBe(BRAND_ACCENT)
  })

  it('falls back to the brand for anything unusable', () => {
    expect(normalizeAccent(null)).toBe(BRAND_ACCENT)
    expect(normalizeAccent(undefined)).toBe(BRAND_ACCENT)
    expect(normalizeAccent('')).toBe(BRAND_ACCENT)
    expect(normalizeAccent('teal')).toBe(BRAND_ACCENT)
    expect(normalizeAccent('#fff')).toBe(BRAND_ACCENT)
    expect(normalizeAccent(42)).toBe(BRAND_ACCENT)
  })
})

describe('accentForeground', () => {
  it('puts white text on a dark accent', () => {
    expect(accentForeground('#0f766e')).toBe('#ffffff')
    expect(accentForeground(BRAND_ACCENT)).toBe('#ffffff')
  })

  it('flips to ink on a pale accent so the label stays readable', () => {
    expect(accentForeground('#fde68a')).toBe(INK)
    expect(accentForeground('#ffffff')).toBe(INK)
  })
})

describe('applyAccent', () => {
  it('writes both custom properties on the given root', () => {
    const root = document.createElement('div')

    const applied = applyAccent('#0F766E', root)

    expect(applied).toBe('#0f766e')
    expect(root.style.getPropertyValue('--color-accent')).toBe('#0f766e')
    expect(root.style.getPropertyValue('--color-accent-fg')).toBe('#ffffff')
  })

  it('writes the brand terracotta when the salon never chose', () => {
    const root = document.createElement('div')

    applyAccent('#6366f1', root)

    expect(root.style.getPropertyValue('--color-accent')).toBe(BRAND_ACCENT)
  })
})

describe('THEME_SWATCHES', () => {
  it('leads with the brand terracotta and holds eight normalized hexes', () => {
    expect(THEME_SWATCHES[0]).toBe(BRAND_ACCENT)
    expect(THEME_SWATCHES).toHaveLength(8)
    THEME_SWATCHES.forEach((hex) => expect(normalizeAccent(hex)).toBe(hex))
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/lib/theme.spec.js`
Expected: FAIL — `Failed to resolve import "@/lib/theme"`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/lib/theme.js`:

```js
/*
 * The salon's accent. One hex arrives from the API; everything the UI needs —
 * the shade ramp, the text colour that sits on top — is derived from it. The
 * ramp itself lives in CSS (color-mix over --color-accent); only the two
 * custom properties below are written from JS.
 */

// SalonHub's own terracotta. Also the answer whenever a salon has not chosen.
export const BRAND_ACCENT = '#c65d3b'
export const INK = '#241c18'

// The settings column defaults to this indigo, so a row holding it tells us
// nothing about the owner's taste. SalonSiteView.vue reads it the same way.
const UNCHOSEN = '#6366f1'

export const ACCENT_STORAGE_KEY = 'salonhub.accent'

// Offered in the settings picker and the onboarding wizard — imported by both
// so the two screens cannot drift apart.
export const THEME_SWATCHES = [
  BRAND_ACCENT, // terracotta
  '#be123c', // rose
  '#b45309', // amber
  '#166534', // forest
  '#0f766e', // teal
  '#0369a1', // blue
  '#7c3aed', // violet
  '#334155', // slate
]

const HEX = /^#?([0-9a-f]{6})$/i

export function normalizeAccent(value) {
  if (typeof value !== 'string') return BRAND_ACCENT

  const match = HEX.exec(value.trim())
  if (!match) return BRAND_ACCENT

  const hex = `#${match[1].toLowerCase()}`

  return hex === UNCHOSEN ? BRAND_ACCENT : hex
}

function linearChannel(value) {
  const channel = value / 255

  return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
}

export function relativeLuminance(hex) {
  const digits = normalizeAccent(hex).slice(1)
  const r = linearChannel(parseInt(digits.slice(0, 2), 16))
  const g = linearChannel(parseInt(digits.slice(2, 4), 16))
  const b = linearChannel(parseInt(digits.slice(4, 6), 16))

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/*
 * No swatch has to be forbidden: a pale accent simply gets dark text. The
 * threshold sits well above mid-grey because white-on-accent is the house
 * look — only genuinely light hues should flip.
 */
export function accentForeground(hex) {
  return relativeLuminance(hex) >= 0.55 ? INK : '#ffffff'
}

export function applyAccent(hex, root = document.documentElement) {
  const accent = normalizeAccent(hex)

  root.style.setProperty('--color-accent', accent)
  root.style.setProperty('--color-accent-fg', accentForeground(accent))

  return accent
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/lib/theme.spec.js`
Expected: PASS — 9 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/theme.js frontend/src/lib/theme.spec.js
git commit -m "$(cat <<'EOF'
feat(theme): derive a legible accent from the salon's chosen colour

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Ship `theme_color` on the session payload

**Files:**
- Modify: `backend/app/Http/Resources/OrganizationResource.php`
- Modify: `backend/app/Http/Controllers/Auth/AuthController.php:38,97,122` (the three `->load('domains')` calls)
- Test: `backend/tests/Feature/Auth/SessionThemeTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `organization.theme_color` — a `?string` present on the JSON of `POST /api/auth/register`, `POST /api/auth/login` and `GET /api/auth/me`. `null` when the organization has no settings row yet.

**Context:** `theme_color` lives on the `settings` table, not `organizations`. `Organization::setting()` is a `HasOne` (`backend/app/Models/Organization.php:124`). The three auth responses already call `->load('domains')`; add `'setting'` there so the resource never issues a lazy query per response.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Auth/SessionThemeTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin UI paints itself with the salon's accent, and staff never touch
 * the owner-only settings endpoints — so the colour has to ride along on the
 * session payload every role already receives.
 */
class SessionThemeTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Alpha',
            'slug' => 'alpha',
            'email' => 'owner@alpha.test',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'Staffer',
            'email' => 'staff@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        return [$org, $user];
    }

    public function test_me_carries_the_salon_theme_colour_for_a_staff_user(): void
    {
        [$org, $user] = $this->scaffold();
        Setting::create(['organization_id' => $org->id, 'theme_color' => '#0f766e']);

        $response = $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('organization.theme_color', '#0f766e');
    }

    public function test_login_carries_the_theme_colour(): void
    {
        [$org, $user] = $this->scaffold();
        Setting::create(['organization_id' => $org->id, 'theme_color' => '#be123c']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'staff@alpha.test',
            'password' => 'secret1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('organization.theme_color', '#be123c');
    }

    public function test_a_salon_without_a_settings_row_reports_a_null_theme_colour(): void
    {
        [, $user] = $this->scaffold();

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('organization.theme_color', null);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --filter=SessionThemeTest`
Expected: FAIL — `Property [organization.theme_color] does not exist` on the first two cases.

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Resources/OrganizationResource.php`, add to the `toArray` payload directly after `'currency' => $this->currency,`:

```php
            // The admin UI accents itself with this, and staff/managers are
            // barred from the settings endpoints — so it travels with the
            // session instead. Null until the owner saves a profile once.
            'theme_color' => $this->themeColor(),
```

and add the resolver beside `primaryDomain()`:

```php
    /**
     * Read the settings row's colour, preferring the loaded relation so a
     * list of organizations does not fire one query per row.
     */
    protected function themeColor(): ?string
    {
        if ($this->relationLoaded('setting')) {
            return $this->setting?->theme_color;
        }

        return $this->setting()->value('theme_color');
    }
```

In `backend/app/Http/Controllers/Auth/AuthController.php`, change all three occurrences of:

```php
new OrganizationResource($organization->load('domains'))
```

to:

```php
new OrganizationResource($organization->load(['domains', 'setting']))
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=SessionThemeTest`
Expected: PASS — 3 tests.

Run: `cd backend && php artisan test`
Expected: PASS — the whole suite, no regressions.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Resources/OrganizationResource.php backend/app/Http/Controllers/Auth/AuthController.php backend/tests/Feature/Auth/SessionThemeTest.php
git commit -m "$(cat <<'EOF'
feat(api): send the salon theme colour with the session payload

Every role needs the accent, but only the owner may read the settings
endpoints, so the colour travels on register/login/me instead.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Accent tokens, theme store, boot wiring

**Files:**
- Modify: `frontend/src/assets/main.css` (inside the existing `@theme` block)
- Create: `frontend/src/stores/theme.js`
- Create: `frontend/src/stores/theme.spec.js`
- Modify: `frontend/src/main.js`
- Modify: `frontend/src/stores/auth.js` (`setSession`, `fetchMe`, `clearSession`)

**Interfaces:**
- Consumes: `normalizeAccent`, `applyAccent`, `BRAND_ACCENT`, `ACCENT_STORAGE_KEY` from Task 1; `organization.theme_color` from Task 2.
- Produces:
  - `useThemeStore()` with `accent: Ref<string>`, `setAccent(hex: unknown): void`, `reset(): void`
  - CSS utilities `bg-accent-500`, `text-accent-600`, `border-accent-200`, `text-accent-fg`, `ring-accent-400` and the rest of the family, usable from any component.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/stores/theme.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useThemeStore } from '@/stores/theme'
import { BRAND_ACCENT, ACCENT_STORAGE_KEY } from '@/lib/theme'

describe('theme store', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.style.removeProperty('--color-accent')
    setActivePinia(createPinia())
  })

  it('starts on the brand terracotta when nothing was remembered', () => {
    expect(useThemeStore().accent).toBe(BRAND_ACCENT)
  })

  it('paints the document and remembers the choice', () => {
    const theme = useThemeStore()

    theme.setAccent('#0F766E')

    expect(theme.accent).toBe('#0f766e')
    expect(document.documentElement.style.getPropertyValue('--color-accent')).toBe('#0f766e')
    expect(localStorage.getItem(ACCENT_STORAGE_KEY)).toBe('#0f766e')
  })

  it('restores the remembered colour on a fresh store', () => {
    localStorage.setItem(ACCENT_STORAGE_KEY, '#be123c')

    expect(useThemeStore().accent).toBe('#be123c')
  })

  it('normalizes rubbish instead of trusting it', () => {
    const theme = useThemeStore()

    theme.setAccent('not-a-colour')

    expect(theme.accent).toBe(BRAND_ACCENT)
  })

  it('returns to the brand on reset, forgetting the salon', () => {
    const theme = useThemeStore()
    theme.setAccent('#0f766e')

    theme.reset()

    expect(theme.accent).toBe(BRAND_ACCENT)
    expect(localStorage.getItem(ACCENT_STORAGE_KEY)).toBeNull()
  })
})
```

Add to `frontend/src/stores/auth.spec.js` (append inside the file's outermost scope; keep the existing imports and add `useThemeStore` from `@/stores/theme`):

```js
describe('auth store — accent handover', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('adopts the salon accent when a session starts', () => {
    useAuthStore().setSession({
      token: 't',
      user: { id: 1, role: 'staff' },
      organization: { id: 9, theme_color: '#0f766e' },
    })

    expect(useThemeStore().accent).toBe('#0f766e')
  })

  it('drops back to the brand when the session ends', () => {
    const auth = useAuthStore()
    auth.setSession({
      token: 't',
      user: { id: 1, role: 'staff' },
      organization: { id: 9, theme_color: '#0f766e' },
    })

    auth.clearSession()

    expect(useThemeStore().accent).toBe(BRAND_ACCENT)
  })
})
```

(Import `BRAND_ACCENT` from `@/lib/theme` at the top of `auth.spec.js`.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx vitest run src/stores/theme.spec.js src/stores/auth.spec.js`
Expected: FAIL — `Failed to resolve import "@/stores/theme"`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/stores/theme.js`:

```js
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { ACCENT_STORAGE_KEY, BRAND_ACCENT, applyAccent, normalizeAccent } from '@/lib/theme'

/*
 * The accent the admin surface is wearing. Seeded from localStorage so a
 * reload does not flash terracotta before /auth/me answers, then corrected
 * by the session payload and by the settings picker's live preview.
 */
export const useThemeStore = defineStore('theme', () => {
  const accent = ref(normalizeAccent(localStorage.getItem(ACCENT_STORAGE_KEY)))

  function setAccent(value) {
    accent.value = applyAccent(value)
    localStorage.setItem(ACCENT_STORAGE_KEY, accent.value)
  }

  // Logging out must not leave the next salon — or the login page — wearing
  // the previous tenant's colour.
  function reset() {
    accent.value = applyAccent(BRAND_ACCENT)
    localStorage.removeItem(ACCENT_STORAGE_KEY)
  }

  return { accent, setAccent, reset }
})
```

In `frontend/src/assets/main.css`, add inside the existing `@theme { … }` block, after the `--color-brand-700` line:

```css
  /*
   * The tenant accent. Every shade is a color-mix over --color-accent, so
   * overriding that one property at runtime moves the whole ramp. brand-*
   * above stays SalonHub's own identity — marketing must not repaint per
   * salon. --color-accent-fg is the text colour that sits ON the accent and
   * is computed in lib/theme.js, because CSS cannot pick it.
   */
  --color-accent: #c65d3b;
  --color-accent-fg: #ffffff;
  --color-accent-50: color-mix(in oklch, var(--color-accent) 8%, white);
  --color-accent-100: color-mix(in oklch, var(--color-accent) 18%, white);
  --color-accent-200: color-mix(in oklch, var(--color-accent) 35%, white);
  --color-accent-300: color-mix(in oklch, var(--color-accent) 55%, white);
  --color-accent-400: color-mix(in oklch, var(--color-accent) 78%, white);
  --color-accent-500: var(--color-accent);
  --color-accent-600: color-mix(in oklch, var(--color-accent) 82%, black);
  --color-accent-700: color-mix(in oklch, var(--color-accent) 65%, black);
```

In `frontend/src/main.js`, apply the remembered accent before mount — after the CSS import, before `createApp`:

```js
import { ACCENT_STORAGE_KEY, applyAccent } from './lib/theme'

// Paint before the first frame: waiting for /auth/me would flash the brand
// terracotta and then snap to the salon's colour.
applyAccent(localStorage.getItem(ACCENT_STORAGE_KEY))
```

In `frontend/src/stores/auth.js`, import the theme store at the top:

```js
import { useThemeStore } from '@/stores/theme'
```

then inside `setSession`, after `organization.value = data.organization`:

```js
    // The whole admin surface accents itself with the salon's colour.
    useThemeStore().setAccent(data.organization?.theme_color)
```

inside `fetchMe`, after `organization.value = data.organization`:

```js
    useThemeStore().setAccent(data.organization?.theme_color)
```

and inside `clearSession`, after `localStorage.removeItem(TOKEN_KEY)`:

```js
    useThemeStore().reset()
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npm run test:unit`
Expected: PASS — the new theme/auth cases plus all 23 existing spec files.

- [ ] **Step 5: Verify the tokens compile**

Run: `cd frontend && npm run build`
Expected: build succeeds; `dist/assets/*.css` contains `--color-accent-500`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/assets/main.css frontend/src/stores/theme.js frontend/src/stores/theme.spec.js frontend/src/stores/auth.js frontend/src/stores/auth.spec.js frontend/src/main.js
git commit -m "$(cat <<'EOF'
feat(theme): drive an accent token ramp from the salon's colour

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `sh-*` component primitives

**Files:**
- Modify: `frontend/src/assets/main.css` (the existing `@layer components` block)

**Interfaces:**
- Consumes: `accent-*` tokens from Task 3.
- Produces: `.sh-card`, `.sh-btn`, `.sh-btn-primary`, `.sh-btn-ghost`, `.sh-btn-danger`, `.sh-input`, `.sh-label`, `.sh-error`, `.sh-table`, `.sh-badge` + `.sh-badge-{pending,confirmed,completed,cancelled,no-show}`, `.sh-empty`. `.auth-label` / `.auth-input` / `.auth-button` / `.auth-link` keep working as aliases.

This task has no unit test — these are CSS declarations with no behavior to assert, and the view specs that consume them arrive in Tasks 6–11. Verification is a successful build plus a visual check.

- [ ] **Step 1: Replace the `@layer components` block**

In `frontend/src/assets/main.css`, replace the whole existing `@layer components { … }` block (and the comment above it) with:

```css
/*
 * Admin surface primitives. Every view used to hand-roll its own slate/indigo
 * utility strings, so card radii and table headers drifted page to page. The
 * auth-* names are kept as aliases of the sh-* ones — five auth views point at
 * them and convert in their own commit.
 */
@layer components {
  .sh-card {
    @apply rounded-2xl border border-ink/10 bg-white shadow-sm;
  }

  .sh-btn {
    @apply inline-flex items-center justify-center gap-2 rounded-full border border-ink/15 bg-white px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-paper focus-visible:ring-2 focus-visible:ring-accent-400 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60;
  }

  .sh-btn-primary {
    @apply border-transparent bg-accent-500 text-accent-fg shadow-sm hover:bg-accent-600;
  }

  .sh-btn-ghost {
    @apply border-transparent bg-transparent text-accent-600 shadow-none hover:bg-accent-50;
  }

  .sh-btn-danger {
    @apply border-transparent bg-rose-600 text-white hover:bg-rose-700;
  }

  .sh-label {
    @apply mb-1.5 block text-sm font-medium text-ink/75;
  }

  .sh-input {
    @apply w-full rounded-xl border border-ink/15 bg-white px-3.5 py-2.5 text-sm text-ink transition-colors outline-none placeholder:text-ink/35 focus:border-accent-300 focus:ring-2 focus:ring-accent-200 disabled:cursor-not-allowed disabled:bg-paper;
  }

  .sh-error {
    @apply mt-1.5 text-sm text-rose-600;
  }

  .sh-table {
    @apply w-full text-left text-sm text-ink;
  }

  .sh-table thead th {
    @apply border-b border-ink/10 px-4 py-3 text-xs font-semibold tracking-wider text-ink/50 uppercase;
  }

  .sh-table tbody td {
    @apply border-b border-ink/[0.06] px-4 py-3 align-middle;
  }

  .sh-table tbody tr:last-child td {
    @apply border-b-0;
  }

  /*
   * Statuses stay on fixed semantic hues rather than the accent — a salon
   * that picks rose must not end up with a cancelled badge that reads as
   * confirmed.
   */
  .sh-badge {
    @apply inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold;
  }

  .sh-badge-pending {
    @apply bg-amber-100 text-amber-800;
  }

  .sh-badge-confirmed {
    @apply bg-sky-100 text-sky-800;
  }

  .sh-badge-completed {
    @apply bg-emerald-100 text-emerald-800;
  }

  .sh-badge-cancelled {
    @apply bg-rose-100 text-rose-700;
  }

  .sh-badge-no-show {
    @apply bg-ink/10 text-ink/60;
  }

  .sh-empty {
    @apply rounded-2xl border border-dashed border-ink/15 bg-white/60 px-6 py-12 text-center text-sm text-ink/55;
  }

  .auth-label {
    @apply sh-label;
  }

  .auth-input {
    @apply sh-input rounded-xl shadow-sm;
  }

  .auth-button {
    @apply sh-btn sh-btn-primary w-full px-5 py-3 text-base shadow-lg shadow-accent-500/25 transition-all duration-200 hover:-translate-y-0.5 disabled:translate-y-0 disabled:shadow-none;
  }

  .auth-link {
    @apply font-semibold text-accent-600 transition-colors hover:text-accent-700;
  }

  .auth-error {
    @apply sh-error;
  }

  .auth-alert {
    @apply rounded-xl border px-4 py-3 text-sm;
  }
}
```

- [ ] **Step 2: Verify the stylesheet compiles**

Run: `cd frontend && npm run build`
Expected: build succeeds. If Tailwind rejects an `@apply` of another custom class (the `auth-*` aliases apply `sh-*`), replace that alias body with the same declarations spelled out as utilities rather than nesting the alias.

- [ ] **Step 3: Verify nothing regressed**

Run: `cd frontend && npm run test:unit`
Expected: PASS — all existing specs.

- [ ] **Step 4: Eyeball an auth page**

Run: `cd frontend && npm run dev`, open `/login`.
Expected: the form still renders correctly — inputs, primary button, links — now in accent terracotta rather than the old brand-500 (visually near-identical, since they share the hex).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/assets/main.css
git commit -m "$(cat <<'EOF'
feat(ui): add shared admin primitives for cards, buttons, tables and badges

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `PageHeader.vue`

**Files:**
- Create: `frontend/src/components/PageHeader.vue`
- Test: `frontend/src/components/PageHeader.spec.js`

**Interfaces:**
- Consumes: `sh-*` classes from Task 4.
- Produces: `<PageHeader title="…" subtitle="…"><template #actions>…</template></PageHeader>` — props `title: String` (required), `subtitle: String` (default `''`); one named slot, `actions`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/components/PageHeader.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PageHeader from '@/components/PageHeader.vue'

describe('PageHeader', () => {
  it('renders the title and subtitle', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Calendar', subtitle: '1 appointment in this view.' },
    })

    expect(wrapper.find('h1').text()).toBe('Calendar')
    expect(wrapper.text()).toContain('1 appointment in this view.')
  })

  it('omits the subtitle line when there is nothing to say', () => {
    const wrapper = mount(PageHeader, { props: { title: 'Staff' } })

    expect(wrapper.find('p').exists()).toBe(false)
  })

  it('renders a page action when one is provided', () => {
    const wrapper = mount(PageHeader, {
      props: { title: 'Appointments' },
      slots: { actions: '<button>New booking</button>' },
    })

    expect(wrapper.find('button').text()).toBe('New booking')
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/components/PageHeader.spec.js`
Expected: FAIL — cannot resolve `@/components/PageHeader.vue`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/components/PageHeader.vue`:

```vue
<script setup>
/*
 * The title block every admin page opens with. Views render it themselves
 * rather than the layout reading route meta — the subtitle is usually derived
 * from loaded data ("1 appointment in this view"), which a static route
 * label cannot express.
 */
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})
</script>

<template>
  <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
      <h1 class="font-display text-4xl leading-tight text-ink sm:text-5xl">{{ title }}</h1>
      <p v-if="subtitle" class="mt-2 text-sm text-ink/60">{{ subtitle }}</p>
    </div>

    <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/components/PageHeader.spec.js`
Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/PageHeader.vue frontend/src/components/PageHeader.spec.js
git commit -m "$(cat <<'EOF'
feat(ui): add the shared admin page header

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Dashboard shell

**Files:**
- Modify: `frontend/src/layouts/DashboardLayout.vue` (template + the `nav` constant; the script's verification-banner logic is unchanged)
- Modify: `frontend/src/layouts/DashboardLayout.spec.js`

**Interfaces:**
- Consumes: `accent-*` tokens (Task 3), `sh-*` classes (Task 4).
- Produces: the shell every admin view renders inside. Views keep rendering into the default `<RouterView>` slot and supply their own `PageHeader`.

**Decisions this task settles:** the primary page action lives in `PageHeader` (page body), not the sticky bar — the bar carries breadcrumb, today's date and Help. Logout moves from the bar into the sidebar's organization card.

- [ ] **Step 1: Write the failing test**

Replace the contents of `frontend/src/layouts/DashboardLayout.spec.js` with the file below. The three existing Finance cases are preserved verbatim; two group-header cases are new.

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

// Mock only the axios calls, matching the house pattern.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import router from '@/router/index'
import { useAuthStore } from '@/stores/auth'
import DashboardLayout from '@/layouts/DashboardLayout.vue'

function loginAs(role) {
  useAuthStore().setSession({
    token: 'test-token',
    user: { id: 1, name: 'Test user', role },
    organization: { id: 9, name: 'Heaven Touch Salon', subscription_plan: 'free' },
  })
}

describe('DashboardLayout sidebar — Finance entry', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue({ data: { data: {} } })
    await router.replace('/dashboard')
  })

  it('offers Finance to an owner', async () => {
    loginAs('owner')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    const link = wrapper.findAll('a').find((a) => a.text() === 'Finance')
    expect(link).toBeDefined()
    expect(link.attributes('href')).toBe('/finance')
  })

  it('hides Finance from a manager', async () => {
    loginAs('manager')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    expect(wrapper.findAll('a').find((a) => a.text() === 'Finance')).toBeUndefined()
  })

  it('hides Finance from staff', async () => {
    loginAs('staff')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    expect(wrapper.findAll('a').find((a) => a.text() === 'Finance')).toBeUndefined()
  })
})

describe('DashboardLayout sidebar — nav groups', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue({ data: { data: {} } })
    await router.replace('/dashboard')
  })

  function groupHeadings(wrapper) {
    return wrapper.findAll('[data-nav-group]').map((el) => el.text())
  }

  it('shows every group to an owner', () => {
    loginAs('owner')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    expect(groupHeadings(wrapper)).toEqual(['Operate', 'Business', 'Insight', 'Presence'])
  })

  it('drops a group whose every item is out of the role’s reach', () => {
    loginAs('staff')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    // Gallery is owner/manager work and Settings is owner-only, so Presence
    // disappears for staff. Insight survives on Reviews alone.
    expect(groupHeadings(wrapper)).toEqual(['Operate', 'Business', 'Insight'])
  })

  it('names the salon and its plan in the sidebar footer', () => {
    loginAs('owner')
    const wrapper = mount(DashboardLayout, { global: { plugins: [router] } })

    const footer = wrapper.get('[data-org-card]')
    expect(footer.text()).toContain('Heaven Touch Salon')
    expect(footer.text()).toContain('Free plan')
  })
})
```

**Do not change any item's `roles` list in this task.** Regrouping is presentation; who may reach a page is policy, and the spec changes none of it. `Reviews` stays unrestricted exactly as it is today, which is why staff keep `Insight`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/layouts/DashboardLayout.spec.js`
Expected: FAIL — no `[data-nav-group]` elements found.

- [ ] **Step 3: Rewrite the nav constant**

In `frontend/src/layouts/DashboardLayout.vue`, replace the flat `nav` array and the `visibleNav` computed with a grouped structure. Keep every existing `d` path string exactly as it is today — copy them across, do not retype them.

```js
// `d` holds a heroicons-style outline path so every nav item renders through
// one <svg> template instead of a bespoke icon block each. Items carry the
// roles their policy allows, so the sidebar never offers a page the API
// would refuse.
const navGroups = [
  {
    label: 'Operate',
    items: [
      { name: 'Dashboard', to: '/dashboard', d: '…keep existing Dashboard path…' },
      { name: 'Appointments', to: '/appointments', d: '…keep existing Appointments path…' },
      { name: 'Calendar', to: '/calendar', d: '…keep existing Calendar path…' },
    ],
  },
  {
    label: 'Business',
    items: [
      { name: 'Branches', to: '/branches', d: '…keep existing Branches path…' },
      { name: 'Services', to: '/services', d: '…keep existing Services path…' },
      { name: 'Staff', to: '/staff', d: '…keep existing Staff path…' },
      { name: 'Customers', to: '/customers', d: '…keep existing Customers path…' },
    ],
  },
  {
    label: 'Insight',
    items: [
      { name: 'Reports', to: '/reports', roles: ['owner', 'manager'], d: '…keep…' },
      { name: 'Finance', to: '/finance', roles: ['owner'], d: '…keep…' },
      { name: 'Reviews', to: '/reviews', d: '…keep existing Reviews path…' },
    ],
  },
  {
    label: 'Presence',
    items: [
      { name: 'Gallery', to: '/gallery', roles: ['owner', 'manager'], d: '…keep…' },
      { name: 'Settings', to: '/settings', roles: ['owner'], d: '…keep…' },
    ],
  },
]

// A group with nothing in it for this role renders no heading at all.
const visibleGroups = computed(() =>
  navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.roles || item.roles.includes(authStore.role)),
    }))
    .filter((group) => group.items.length > 0)
)
```

Add the two computeds the sidebar footer needs, beside `organization`:

```js
// Two letters is enough to recognise your own salon at a glance.
const orgInitials = computed(() =>
  (organization.value?.name || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join('') || 'S'
)

const planLabel = computed(() => {
  const plan = organization.value?.subscription_plan
  return plan ? `${plan[0].toUpperCase()}${plan.slice(1)} plan` : ''
})
```

Add the breadcrumb pieces (import `useRoute` from `vue-router` alongside the existing `useRouter`):

```js
const route = useRoute()

// The bar names the page the sidebar highlighted, so the two never disagree.
const pageLabel = computed(() => {
  const match = navGroups.flatMap((group) => group.items).find((item) => route.path.startsWith(item.to))
  return match?.name ?? ''
})

const today = computed(() =>
  new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })
)
```

- [ ] **Step 4: Rewrite the template**

Replace the whole `<template>` block with:

```vue
<template>
  <div class="min-h-screen bg-paper">
    <!-- Mobile backdrop -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-30 bg-ink/50 lg:hidden"
      @click="sidebarOpen = false"
    ></div>

    <!-- Sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-ink transition-transform duration-200 ease-in-out lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-20 shrink-0 items-center gap-3 px-6">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-accent-500 text-sm font-bold text-accent-fg">
          S
        </div>
        <span class="font-display text-xl font-semibold text-white">SalonHub</span>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-4">
        <div v-for="group in visibleGroups" :key="group.label">
          <p
            data-nav-group
            class="px-3 pb-2 text-[0.68rem] font-semibold tracking-[0.18em] text-white/35 uppercase"
          >
            {{ group.label }}
          </p>

          <RouterLink
            v-for="item in group.items"
            :key="item.to"
            :to="item.to"
            class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/65 transition hover:bg-white/10 hover:text-white"
            active-class="!bg-accent-500 !text-accent-fg"
            @click="sidebarOpen = false"
          >
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.d" />
            </svg>
            {{ item.name }}
          </RouterLink>
        </div>
      </nav>

      <div data-org-card class="mt-auto flex items-center gap-3 border-t border-white/10 px-4 py-4">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white">
          {{ orgInitials }}
        </div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-white">{{ organization?.name }}</p>
          <p class="text-xs text-white/45">{{ planLabel }}</p>
        </div>
        <button
          type="button"
          class="shrink-0 text-xs font-medium text-white/55 transition hover:text-white"
          @click="onLogout"
        >
          Logout
        </button>
      </div>
    </aside>

    <!-- Content column -->
    <div class="lg:pl-64">
      <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-ink/10 bg-paper/85 px-4 backdrop-blur sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
          <button
            type="button"
            class="rounded-lg p-2 text-ink/60 transition hover:bg-ink/5 hover:text-ink lg:hidden"
            aria-label="Open navigation"
            @click="sidebarOpen = true"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
            </svg>
          </button>
          <span class="truncate text-sm font-medium text-ink">{{ pageLabel }}</span>
          <span class="hidden text-ink/20 sm:inline">|</span>
          <span class="hidden truncate text-sm text-ink/55 sm:inline">{{ today }}</span>
        </div>

        <RouterLink to="/settings" class="shrink-0 text-sm font-medium text-ink/60 transition hover:text-ink">
          Help
        </RouterLink>
      </header>

      <main class="px-4 py-8 sm:px-6 lg:px-10">
        <div class="mx-auto max-w-6xl">
          <div
            v-if="showVerifyBanner"
            class="mb-6 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="flex items-start gap-2">
              <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
              </svg>
              <span>
                <template v-if="resendState === 'idle'">
                  Confirm <span class="font-medium">{{ authStore.user.email }}</span> to secure your
                  account — check your inbox for the verification link.
                </template>
                <template v-else>{{ resendMessage }}</template>
              </span>
            </div>
            <button
              v-if="resendState !== 'sent'"
              type="button"
              :disabled="resendState === 'sending'"
              class="sh-btn shrink-0 border-amber-300 text-amber-900 hover:bg-amber-100"
              @click="resendVerification"
            >
              {{ resendState === 'sending' ? 'Sending…' : 'Resend email' }}
            </button>
          </div>

          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd frontend && npm run test:unit`
Expected: PASS — the six layout cases and every other spec.

- [ ] **Step 6: Verify in the browser**

Run: `cd frontend && npm run dev`, log in, visit `/calendar`.
Expected: dark sidebar with four grouped sections, terracotta active pill, org card pinned at the foot, cream page, breadcrumb + date in the bar. Narrow the window below `lg` and confirm the sidebar collapses behind the hamburger and its backdrop.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/layouts/DashboardLayout.vue frontend/src/layouts/DashboardLayout.spec.js
git commit -m "$(cat <<'EOF'
feat(ui): rebuild the dashboard shell on the SalonHub brand

Dark grouped sidebar with an organization card, cream content column, and
a breadcrumb bar. Group headings disappear when a role can reach none of
their pages.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Theme picker in settings and onboarding

**Files:**
- Modify: `frontend/src/components/settings/SalonProfileSettings.vue` (the "Theme colour" block at ~lines 196–208, plus `save()` at ~85 and the script head)
- Create: `frontend/src/components/settings/SalonProfileSettings.spec.js`
- Modify: `frontend/src/views/onboarding/StepLook.vue` (the `THEMES` constant at line 11 and the swatch loop at ~177–188)
- Modify: `frontend/src/views/onboarding/StepLook.spec.js` (only if a case asserts `#4f46e5` as the default)

**Interfaces:**
- Consumes: `THEME_SWATCHES`, `normalizeAccent` (Task 1), `useThemeStore` (Task 3).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/components/settings/SalonProfileSettings.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() } }
})

import api from '@/lib/api'
import SalonProfileSettings from '@/components/settings/SalonProfileSettings.vue'
import { useThemeStore } from '@/stores/theme'
import { THEME_SWATCHES } from '@/lib/theme'

const PROFILE = {
  name: 'Heaven Touch',
  email: 'owner@heaven.test',
  phone: '',
  country: 'BD',
  timezone: 'Asia/Dhaka',
  currency: 'BDT',
  theme_color: '#6366f1',
  about: '',
  facebook: '',
  instagram: '',
  website: '',
  slug: 'heaven',
  logo_url: null,
  cover_image_url: null,
}

async function mountSettings() {
  vi.mocked(api.get).mockResolvedValue({ data: { data: { ...PROFILE } } })
  const wrapper = mount(SalonProfileSettings)
  await flushPromises()
  return wrapper
}

describe('SalonProfileSettings — theme picker', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.put).mockReset()
  })

  it('offers every curated swatch', async () => {
    const wrapper = await mountSettings()

    expect(wrapper.findAll('[data-swatch]')).toHaveLength(THEME_SWATCHES.length)
  })

  it('previews the chosen colour immediately, before saving', async () => {
    const wrapper = await mountSettings()
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')

    expect(useThemeStore().accent).toBe('#0f766e')
  })

  it('saves the chosen colour with the profile', async () => {
    const wrapper = await mountSettings()
    vi.mocked(api.put).mockResolvedValue({ data: { data: { ...PROFILE, theme_color: '#0f766e' } } })
    const teal = THEME_SWATCHES.indexOf('#0f766e')

    await wrapper.findAll('[data-swatch]')[teal].trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(api.put).mock.calls[0][1].theme_color).toBe('#0f766e')
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/components/settings/SalonProfileSettings.spec.js`
Expected: FAIL — no `[data-swatch]` elements found.

- [ ] **Step 3: Implement the picker**

In `SalonProfileSettings.vue`, add to the script imports:

```js
import { THEME_SWATCHES } from '@/lib/theme'
import { useThemeStore } from '@/stores/theme'

const themeStore = useThemeStore()

// Selecting a colour repaints the app at once — the owner judges it against
// the real sidebar rather than a swatch.
function chooseTheme(hex) {
  form.theme_color = hex
  themeStore.setAccent(hex)
}
```

Change the `form` initializer's `theme_color: '#6366f1',` to `theme_color: THEME_SWATCHES[0],`, and inside the existing `apply()` function make the theme store follow whatever the API returned — add after the loop that copies keys into `form`:

```js
  themeStore.setAccent(form.theme_color)
```

Replace the "Theme colour" markup (`<label class="text-xs font-medium text-slate-600">Theme colour</label>` and the two inputs beneath it) with:

```vue
        <div class="mt-6">
          <span class="sh-label">Theme colour</span>
          <p class="mb-3 text-xs text-ink/55">
            Accents your dashboard and your public booking pages.
          </p>

          <div class="flex flex-wrap items-center gap-2.5">
            <button
              v-for="hex in THEME_SWATCHES"
              :key="hex"
              data-swatch
              type="button"
              class="h-9 w-9 rounded-full ring-2 ring-offset-2 transition"
              :style="{ backgroundColor: hex }"
              :class="form.theme_color === hex ? 'ring-ink' : 'ring-transparent hover:ring-ink/20'"
              :aria-label="hex"
              :aria-pressed="form.theme_color === hex"
              @click="chooseTheme(hex)"
            />

            <span class="ml-2 h-6 w-px bg-ink/10"></span>

            <label class="flex items-center gap-2 text-xs font-medium text-ink/60">
              Custom
              <input
                :value="form.theme_color"
                type="color"
                class="h-9 w-12 cursor-pointer rounded-lg border border-ink/15 bg-white p-1"
                @input="chooseTheme($event.target.value)"
              />
            </label>

            <input
              :value="form.theme_color"
              type="text"
              class="sh-input w-32 font-mono text-sm uppercase"
              @change="chooseTheme($event.target.value)"
            />
          </div>

          <p v-if="fieldError('theme_color')" class="sh-error">{{ fieldError('theme_color') }}</p>
        </div>
```

In `StepLook.vue`, delete the local `THEMES` constant and import the shared list instead:

```js
import { THEME_SWATCHES } from '@/lib/theme'
```

then replace every remaining `THEMES` reference with `THEME_SWATCHES` — the `themeColor` initializer (line 14), the `onMounted` fallback (line 30), and the `v-for` on the swatch loop (line 179).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npm run test:unit`
Expected: PASS. If `StepLook.spec.js` fails asserting `#4f46e5`, update that expectation to `THEME_SWATCHES[0]` — the default palette deliberately changed.

- [ ] **Step 5: Verify in the browser**

Run: `cd frontend && npm run dev`, open `/settings`, click swatches.
Expected: sidebar, buttons and active nav repaint instantly; a pale custom hex flips button text to dark; saving persists and survives a reload.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/settings/SalonProfileSettings.vue frontend/src/components/settings/SalonProfileSettings.spec.js frontend/src/views/onboarding/StepLook.vue frontend/src/views/onboarding/StepLook.spec.js
git commit -m "$(cat <<'EOF'
feat(settings): pick the salon accent from curated swatches with live preview

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Convert shared components + Dashboard, Appointments, Calendar

**Files:**
- Modify: `frontend/src/components/Modal.vue`, `frontend/src/components/ConfirmDialog.vue`, `frontend/src/components/PaymentModal.vue`, `frontend/src/components/SetupChecklistCard.vue`
- Modify: `frontend/src/views/DashboardView.vue`, `frontend/src/views/AppointmentsView.vue`, `frontend/src/views/CalendarView.vue`
- Test: existing `frontend/src/views/AppointmentsView.spec.js`, `frontend/src/components/PaymentModal.spec.js`, `frontend/src/components/SetupChecklistCard.spec.js` must stay green **unedited**

**Interfaces:**
- Consumes: `sh-*` (Task 4), `PageHeader` (Task 5), accent tokens (Task 3).
- Produces: nothing later tasks import; establishes the conversion pattern Tasks 9–11 repeat.

**Method for every view in this task and Tasks 9–11:**
1. Apply the class mapping table from Global Constraints, top to bottom through the file.
2. Replace the view's own `<h1>`/subtitle block with `<PageHeader>`; move the page's primary button into its `#actions` slot.
3. Change structure only where the mapping requires it. Do not rename refs, reorder logic, or alter data flow.
4. Leave `grep -n "indigo\|slate-" <file>` returning nothing.

- [ ] **Step 1: Confirm the baseline is green**

Run: `cd frontend && npm run test:unit`
Expected: PASS — this is the reference point; anything failing afterwards is yours.

- [ ] **Step 2: Convert the four shared components**

Every view mounts these, so they go first. Apply the mapping table to `Modal.vue`, `ConfirmDialog.vue`, `PaymentModal.vue` and `SetupChecklistCard.vue`. Modal backdrops become `bg-ink/50`; modal panels become `sh-card`; `ConfirmDialog`'s destructive button becomes `sh-btn sh-btn-danger` and its cancel `sh-btn`.

- [ ] **Step 3: Run the tests**

Run: `cd frontend && npx vitest run src/components`
Expected: PASS — `PaymentModal.spec.js` and `SetupChecklistCard.spec.js` unedited.

- [ ] **Step 4: Convert `DashboardView.vue`**

Apply the mapping. The stat tiles become `sh-card p-5`; their figures render `font-display text-3xl text-ink`; the today/upcoming lists use `sh-table`; status pills become `sh-badge sh-badge-<status>`. Header:

```vue
<PageHeader title="Dashboard" :subtitle="todaySummary" />
```

where `todaySummary` is an existing computed or a new one built from data already loaded — do not fetch anything new.

- [ ] **Step 5: Convert `AppointmentsView.vue`**

Apply the mapping. Filters sit in a `sh-card p-4`; the list becomes `sh-table`; the status pill helper returns `sh-badge-*` modifiers instead of inline color strings. Header:

```vue
<PageHeader title="Appointments" :subtitle="`${appointments.length} appointment${appointments.length === 1 ? '' : 's'}`">
  <template #actions>
    <button type="button" class="sh-btn sh-btn-primary" @click="openCreate">New booking</button>
  </template>
</PageHeader>
```

Use the view's existing list ref and open-create handler names rather than these placeholders if they differ.

- [ ] **Step 6: Convert `CalendarView.vue`**

Apply the mapping. The month grid keeps its layout; cell borders become `border-ink/10`, the today marker becomes `bg-accent-500 text-accent-fg`, event chips become `border-l-2 border-accent-500 bg-accent-50 text-ink`, and the Month/Week/Day switch becomes a `sh-card` pill group with the active segment on `bg-white shadow-sm`. Header reproduces the screenshot: `<PageHeader title="Calendar" :subtitle="…count sentence…" />`.

- [ ] **Step 7: Run the full suite**

Run: `cd frontend && npm run test:unit`
Expected: PASS — including `AppointmentsView.spec.js` with no edits.

- [ ] **Step 8: Check for leftovers**

Run: `cd frontend && grep -rn "indigo\|slate-" src/views/DashboardView.vue src/views/AppointmentsView.vue src/views/CalendarView.vue src/components/Modal.vue src/components/ConfirmDialog.vue src/components/PaymentModal.vue src/components/SetupChecklistCard.vue`
Expected: no output.

- [ ] **Step 9: Verify in the browser**

Run: `cd frontend && npm run dev`; visit `/dashboard`, `/appointments`, `/calendar`.
Expected: all three sit on cream, share one card and table treatment, and follow the accent when it changes in settings.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/components/Modal.vue frontend/src/components/ConfirmDialog.vue frontend/src/components/PaymentModal.vue frontend/src/components/SetupChecklistCard.vue frontend/src/views/DashboardView.vue frontend/src/views/AppointmentsView.vue frontend/src/views/CalendarView.vue
git commit -m "$(cat <<'EOF'
refactor(ui): move the daily-operations pages onto the shared primitives

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Convert Branches, Services, Staff, Customers

**Files:**
- Modify: `frontend/src/views/BranchesView.vue`, `frontend/src/views/ServicesView.vue`, `frontend/src/views/StaffView.vue`, `frontend/src/views/CustomersView.vue`
- Test: existing `frontend/src/views/StaffView.spec.js` must stay green **unedited**

**Interfaces:**
- Consumes: `sh-*` (Task 4), `PageHeader` (Task 5).
- Produces: nothing.

- [ ] **Step 1: Convert `BranchesView.vue`**

Apply the mapping table and the four-step method from Task 8. Opening-hours editors become `sh-input` grids inside a `sh-card p-6`. Header: `<PageHeader title="Branches" subtitle="Where your salon operates." >` with the add-branch button in `#actions` as `sh-btn sh-btn-primary`.

- [ ] **Step 2: Convert `ServicesView.vue`**

Apply the mapping. Category groups become `sh-card` sections with `font-display text-lg` titles; the service rows become `sh-table`. Header: `<PageHeader title="Services" subtitle="What customers can book." >` + primary action.

- [ ] **Step 3: Convert `StaffView.vue`**

Apply the mapping. Staff cards become `sh-card p-5`; avatar placeholders `bg-ink/5 text-ink/40`; the working-hours editor uses `sh-input` / `sh-label`. Header: `<PageHeader title="Staff" :subtitle="planLimitSentence" >` reusing whatever free-plan-limit text the view already computes, plus the primary action.

- [ ] **Step 4: Convert `CustomersView.vue`**

Apply the mapping. The search field becomes `sh-input`, the list `sh-table`, the empty state `sh-empty`. Header: `<PageHeader title="Customers" :subtitle="…count sentence…" >`.

- [ ] **Step 5: Run the full suite**

Run: `cd frontend && npm run test:unit`
Expected: PASS — `StaffView.spec.js` unedited.

- [ ] **Step 6: Check for leftovers**

Run: `cd frontend && grep -rn "indigo\|slate-" src/views/BranchesView.vue src/views/ServicesView.vue src/views/StaffView.vue src/views/CustomersView.vue`
Expected: no output.

- [ ] **Step 7: Verify in the browser**

Run: `cd frontend && npm run dev`; visit `/branches`, `/services`, `/staff`, `/customers`, opening each create/edit modal.
Expected: consistent cards, inputs and buttons; modals match Task 8's.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/views/BranchesView.vue frontend/src/views/ServicesView.vue frontend/src/views/StaffView.vue frontend/src/views/CustomersView.vue
git commit -m "$(cat <<'EOF'
refactor(ui): move the business-setup pages onto the shared primitives

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Convert Reports, Finance, Reviews, Gallery, Settings

**Files:**
- Modify: `frontend/src/views/ReportsView.vue`, `frontend/src/views/FinanceView.vue`, `frontend/src/views/ReviewsView.vue`, `frontend/src/views/GalleryView.vue`, `frontend/src/views/SettingsView.vue`, `frontend/src/components/settings/ReminderSettings.vue`, `frontend/src/components/settings/PaymentSettings.vue`
- Test: existing `frontend/src/views/FinanceView.spec.js` must stay green **unedited**

**Interfaces:**
- Consumes: `sh-*` (Task 4), `PageHeader` (Task 5), accent tokens (Task 3).
- Produces: nothing.

- [ ] **Step 1: Convert `ReportsView.vue`**

Apply the mapping. The chart's bars/lines take `fill-accent-500` / `stroke-accent-500` (or `var(--color-accent-500)` if they are inline SVG attributes rather than classes) so the report follows the salon's colour; axis labels `text-ink/50`. The range picker becomes a `sh-card` segmented control matching the Calendar view's. Header: `<PageHeader title="Reports" :subtitle="rangeLabel" >`.

- [ ] **Step 2: Convert `FinanceView.vue`**

Apply the mapping. Money figures render `font-display text-2xl text-ink`; payroll and expense lists become `sh-table`; the finalize action is `sh-btn sh-btn-primary` and any delete `sh-btn sh-btn-danger`. Header: `<PageHeader title="Finance" :subtitle="…period sentence…" >`.

- [ ] **Step 3: Convert `ReviewsView.vue`**

Apply the mapping. Review cards become `sh-card p-5`; stars `text-accent-500`; publish/hide controls `sh-btn` and `sh-btn-ghost`. Header: `<PageHeader title="Reviews" :subtitle="…average/count sentence…" >`.

- [ ] **Step 4: Convert `GalleryView.vue`**

Apply the mapping. The image grid keeps its layout; tiles get `rounded-2xl ring-1 ring-ink/10`; the upload dropzone becomes `sh-empty` with a `sh-btn sh-btn-primary` inside. Header: `<PageHeader title="Gallery" subtitle="Photos shown on your public page." >`.

- [ ] **Step 5: Convert `SettingsView.vue` and its two remaining panels**

Apply the mapping to `SettingsView.vue` (the tab shell), `ReminderSettings.vue` and `PaymentSettings.vue`. Tabs become an underlined row: active tab `border-b-2 border-accent-500 text-ink`, inactive `text-ink/55 hover:text-ink`. Header: `<PageHeader title="Settings" subtitle="Your salon profile, reminders and payments." >`. `SalonProfileSettings.vue`'s remaining slate markup — everything outside the picker Task 7 already converted — is included here.

- [ ] **Step 6: Run the full suite**

Run: `cd frontend && npm run test:unit`
Expected: PASS — `FinanceView.spec.js` and `SalonProfileSettings.spec.js` unedited.

- [ ] **Step 7: Check for leftovers**

Run: `cd frontend && grep -rn "indigo\|slate-" src/views/ReportsView.vue src/views/FinanceView.vue src/views/ReviewsView.vue src/views/GalleryView.vue src/views/SettingsView.vue src/components/settings/`
Expected: no output.

- [ ] **Step 8: Verify in the browser**

Run: `cd frontend && npm run dev`; visit `/reports`, `/finance`, `/reviews`, `/gallery`, `/settings`. Change the accent in settings.
Expected: every page — including the report chart — follows the new colour without a reload.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/views/ReportsView.vue frontend/src/views/FinanceView.vue frontend/src/views/ReviewsView.vue frontend/src/views/GalleryView.vue frontend/src/views/SettingsView.vue frontend/src/components/settings/ReminderSettings.vue frontend/src/components/settings/PaymentSettings.vue
git commit -m "$(cat <<'EOF'
refactor(ui): move the insight and presence pages onto the shared primitives

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Convert auth pages and the onboarding wizard

**Files:**
- Modify: `frontend/src/layouts/AuthLayout.vue`, `frontend/src/layouts/OnboardingLayout.vue`
- Modify: `frontend/src/views/LoginView.vue`, `frontend/src/views/RegisterView.vue`, `frontend/src/views/ForgotPasswordView.vue`, `frontend/src/views/ResetPasswordView.vue`, `frontend/src/views/VerifyEmailView.vue`
- Modify: every `frontend/src/views/onboarding/Step*.vue`
- Modify: `frontend/src/assets/main.css` — delete the `auth-*` aliases once nothing references them

**Interfaces:**
- Consumes: `sh-*` (Task 4).
- Produces: nothing.

**Note:** these pages render for logged-out visitors, where no salon accent is known. That is already handled — `applyAccent(null)` in `main.js` and `themeStore.reset()` on logout both land on the brand terracotta, so auth pages always wear SalonHub's own colour.

- [ ] **Step 1: Convert the two layouts**

Apply the mapping table to `AuthLayout.vue` and `OnboardingLayout.vue`: page background `bg-paper`, panel `sh-card`, headings `font-display text-ink`, the onboarding step indicator's active dot `bg-accent-500`, completed `bg-accent-300`, pending `bg-ink/15`.

- [ ] **Step 2: Convert the five auth views**

Replace each `auth-label` with `sh-label`, `auth-input` with `sh-input`, `auth-button` with `sh-btn sh-btn-primary w-full py-3 text-base`, `auth-link` with a `text-accent-600 hover:text-accent-700 font-semibold` link, and `auth-error` with `sh-error`. Keep `auth-alert` — rename it `sh-alert` and move its definition accordingly.

- [ ] **Step 3: Convert the onboarding steps**

Apply the mapping to every `Step*.vue`. `StepLook.vue`'s swatch loop was already converted in Task 7 — only its surrounding markup changes here.

- [ ] **Step 4: Drop the aliases**

Run: `cd frontend && grep -rn "auth-label\|auth-input\|auth-button\|auth-link\|auth-error" src/`
Expected: no output. Then delete those five alias rules from `main.css`, keeping `sh-alert`.

- [ ] **Step 5: Run the full suite**

Run: `cd frontend && npm run test:unit`
Expected: PASS — every spec, none edited in this task.

Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Check for leftovers across the whole authenticated surface**

Run: `cd frontend && grep -rn "indigo\|slate-" src/views/ src/layouts/ src/components/ --include=*.vue | grep -v "marketing/\|legal/\|SalonSite\|PublicBooking\|ManageBooking\|SalonSearch\|CustomerDashboard\|CustomerLogin\|CustomerLayout\|Landing"`
Expected: no output.

- [ ] **Step 7: Walk the whole flow in the browser**

Run: `cd frontend && npm run dev`. Register a salon → complete onboarding → change the accent in settings → log out → log back in.
Expected: one consistent look throughout; the accent is remembered across the reload with no terracotta flash; the login page shows brand terracotta, not the last salon's colour.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/layouts/ frontend/src/views/LoginView.vue frontend/src/views/RegisterView.vue frontend/src/views/ForgotPasswordView.vue frontend/src/views/ResetPasswordView.vue frontend/src/views/VerifyEmailView.vue frontend/src/views/onboarding/ frontend/src/assets/main.css
git commit -m "$(cat <<'EOF'
refactor(ui): move sign-in and onboarding onto the shared primitives

Retires the auth-* aliases now that nothing points at them.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```
