# SalonHub Marketing / Landing Site Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a public SaaS marketing home page at `/` (hero, features, pricing, FAQ, contact, etc.) that drives salon owners to Register/Login, plus a backend contact endpoint and a post-auth dashboard banner surfacing the salon's booking-site subdomain.

**Architecture:** One new public, rate-limited, non-tenant-scoped backend endpoint (`POST /api/contact`) sending a Mailable to a config-driven address. Everything else is frontend: a Vue 3 SPA landing route added *before* the authenticated `DashboardLayout` record, composed from small single-responsibility marketing section components under `src/components/marketing/`, plus a `SubdomainBanner` on the staff dashboard reading the already-loaded `organization` from the auth store. No billing, no wildcard DNS — pricing is static content, subdomains are surfaced as links only.

**Tech Stack:** Laravel 12 / PHP 8.4 (backend, PHPUnit feature tests, `Mail::fake`), Vue 3.5 + vue-router 5.2 + Pinia 4 + axios (frontend), Tailwind CSS v4 (CSS-based `@import 'tailwindcss'`, `@theme` tokens), self-hosted fonts via `@fontsource-variable/*` (bundled by Vite — no runtime CDN).

## Global Constraints

- No secrets in source. Contact address comes from `config('mail.contact_address')`, backed by env `CONTACT_EMAIL`, default `hello@salonhub.com`. No other hardcoding.
- `POST /api/contact` MUST be rate-limited (`throttle:5,1`) and MUST NOT be tenant-scoped (registered outside both the `auth` and tenant middleware groups, alongside other platform-level public routes).
- Do NOT regress existing staff routes (`/dashboard`, `/appointments`, `/calendar`, `/branches`, `/services`, `/staff`, `/customers`, `/reports`, `/reviews`, `/gallery`, `/settings`) or customer-account routes (`/account`, `/account/login`) when restructuring the router.
- Frontend aesthetic: **modern SaaS with a warm beauty-industry accent** (terracotta/rose). No generic AI-slop defaults — no Inter/Arial/Roboto, no purple-on-white. Commit to the direction. Distinctive display font + clean body font (values in Task 2).
- Self-contained frontend assets: no external CDN fonts/images (would break offline/CSP). Fonts self-hosted via Fontsource npm packages; illustrations are CSS/SVG only.
- Pricing tiers, copy, testimonials, and FAQ are **static marketing content** with no billing behavior. All pricing CTAs route to `/register`.
- Tier names align with the existing `SubscriptionPlan` enum: `free`, `starter`, `business`.
- Frontend verification gate is a clean `npm run build` (matches existing repo convention) plus the manual routing checks named in Task 4.

---

### Task 1: Backend contact endpoint (`POST /api/contact`)

**Files:**
- Modify: `backend/config/mail.php` (add `contact_address` key)
- Create: `backend/app/Http/Requests/Contact/StoreContactRequest.php`
- Create: `backend/app/Mail/ContactMessageMail.php`
- Create: `backend/resources/views/mail/contact/message.blade.php`
- Create: `backend/app/Http/Controllers/ContactController.php`
- Modify: `backend/routes/api.php` (import + one public throttled route line)
- Test: `backend/tests/Feature/ContactTest.php`

**Interfaces:**
- Produces: `POST /api/contact` accepting JSON `{ name: string, email: string, message: string }`; returns `200 {"message": "Thanks — we'll be in touch soon."}` on success, `422` on validation failure, `429` when throttled. Consumed by the frontend `ContactSection` (Task 3).
- `config('mail.contact_address')` → string, the platform inbox address.
- `ContactMessageMail(string $name, string $email, string $message)` — Mailable sent to the contact address.

- [ ] **Step 1: Write the failing feature test**

Create `backend/tests/Feature/ContactTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\ContactMessageMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_submission_sends_mail_to_configured_address(): void
    {
        Mail::fake();
        config(['mail.contact_address' => 'inbox@salonhub.test']);

        $response = $this->postJson('/api/contact', [
            'name' => 'Ada Salon',
            'email' => 'ada@example.com',
            'message' => 'Interested in the Business plan for 3 branches.',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail) {
            return $mail->hasTo('inbox@salonhub.test')
                && $mail->name === 'Ada Salon'
                && $mail->email === 'ada@example.com'
                && str_contains($mail->message, 'Business plan');
        });
    }

    public function test_missing_fields_return_422_and_send_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', ['name' => '', 'email' => 'not-an-email', 'message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        Mail::assertNothingSent();
    }

    public function test_message_over_max_length_is_rejected(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => str_repeat('x', 5001),
        ])->assertStatus(422)->assertJsonValidationErrors(['message']);
    }

    public function test_endpoint_is_rate_limited_after_five_requests_per_minute(): void
    {
        Mail::fake();
        $payload = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello there'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', $payload)->assertOk();
        }

        $this->postJson('/api/contact', $payload)->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=ContactTest`
Expected: FAIL — route `/api/contact` does not exist (404 / method errors), `ContactMessageMail` class not found.

- [ ] **Step 3: Add the contact address config key**

In `backend/config/mail.php`, immediately after the `'from' => [ ... ],` block (ends line ~116), add:

```php
    /*
    |--------------------------------------------------------------------------
    | Platform Contact Address
    |--------------------------------------------------------------------------
    |
    | Where the public marketing-site contact form delivers messages. Kept in
    | config (not hardcoded) so it is env-driven per environment.
    |
    */

    'contact_address' => env('CONTACT_EMAIL', 'hello@salonhub.com'),
```

- [ ] **Step 4: Create the FormRequest**

Create `backend/app/Http/Requests/Contact/StoreContactRequest.php`:

```php
<?php

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
```

- [ ] **Step 5: Create the Mailable**

Create `backend/app/Mail/ContactMessageMail.php` (mirrors the existing `CustomerLoginCodeMail` markdown-mailable pattern):

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $message,
    ) {}

    public function envelope(): Envelope
    {
        // replyTo the sender so the platform team can respond directly.
        return new Envelope(
            subject: 'New SalonHub contact message',
            replyTo: [$this->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.contact.message',
            with: [
                'senderName' => $this->name,
                'senderEmail' => $this->email,
                'body' => $this->message,
            ],
        );
    }
}
```

- [ ] **Step 6: Create the mail blade**

Create `backend/resources/views/mail/contact/message.blade.php`:

```blade
@component('mail::message')
# New contact message

**From:** {{ $senderName }} ({{ $senderEmail }})

@component('mail::panel')
{{ $body }}
@endcomponent

Reply directly to this email to respond to {{ $senderName }}.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
```

- [ ] **Step 7: Create the controller**

Create `backend/app/Http/Controllers/ContactController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contact\StoreContactRequest;
use App\Mail\ContactMessageMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(StoreContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        Mail::to(config('mail.contact_address'))
            ->send(new ContactMessageMail($data['name'], $data['email'], $data['message']));

        return response()->json(['message' => "Thanks — we'll be in touch soon."]);
    }
}
```

- [ ] **Step 8: Register the public route**

In `backend/routes/api.php`, add the import alongside the other `use App\Http\Controllers\...` lines (after `use App\Http\Controllers\CustomerController;`):

```php
use App\Http\Controllers\ContactController;
```

Then add the route as a platform-level public line — place it directly above the `// Platform-wide customer accounts.` comment / `Route::prefix('customer')` group near the end of the file. It sits outside every auth and tenant group:

```php
// Public marketing-site contact form. No auth, not tenant-scoped. Rate-limited
// against spam: 5 requests per minute per IP.
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,1');
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=ContactTest`
Expected: PASS (4 tests). Then run the full suite to confirm no regression: `php artisan test`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add backend/config/mail.php backend/app/Http/Requests/Contact backend/app/Mail/ContactMessageMail.php backend/resources/views/mail/contact backend/app/Http/Controllers/ContactController.php backend/routes/api.php backend/tests/Feature/ContactTest.php
git commit -m "feat: public contact endpoint for marketing site"
```

---

### Task 2: Frontend design foundation (fonts + warm-accent theme tokens)

**Files:**
- Modify: `frontend/package.json` (add two font deps via npm install)
- Modify: `frontend/src/main.js` (import font packages)
- Modify: `frontend/src/assets/main.css` (Tailwind `@theme` tokens: fonts + brand palette)
- Test gate: `npm run build`

**Interfaces:**
- Produces: Tailwind theme tokens usable as utility classes by Tasks 3 & 5:
  - Fonts: `font-display` (Fraunces), `font-body` (Manrope). Body font applied to `<body>` globally.
  - Brand palette utilities: `bg-brand-500`, `text-brand-600`, `border-brand-200`, etc. (scale 50–700), plus `bg-paper` (warm page background) and `text-ink` (warm charcoal).

- [ ] **Step 1: Install the self-hosted font packages**

Run: `cd frontend && npm install @fontsource-variable/fraunces @fontsource-variable/manrope`
Expected: both added to `dependencies` in `package.json`. These bundle woff2 files locally — Vite serves them; no runtime CDN.

- [ ] **Step 2: Import the fonts in the app entry**

In `frontend/src/main.js`, add these imports at the very top (before the existing `import './assets/main.css'` line — order ensures `@font-face` faces are registered):

```js
import '@fontsource-variable/fraunces'
import '@fontsource-variable/manrope'
```

- [ ] **Step 3: Define theme tokens in main.css**

`frontend/src/assets/main.css` currently contains only `@import 'tailwindcss';`. Replace its contents with:

```css
@import 'tailwindcss';

/*
 * SalonHub platform brand — modern SaaS with a warm terracotta/rose accent.
 * Distinct from the per-salon microsite (stone/editorial). Display = Fraunces
 * (characterful serif), body = Manrope (clean humanist sans). Fonts are
 * self-hosted via @fontsource-variable (imported in main.js).
 */
@theme {
  --font-display: 'Fraunces Variable', ui-serif, Georgia, serif;
  --font-body: 'Manrope Variable', ui-sans-serif, system-ui, sans-serif;

  --color-paper: #faf6f1;
  --color-ink: #241c18;

  --color-brand-50: #fbf3ef;
  --color-brand-100: #f6e3da;
  --color-brand-200: #edc6b4;
  --color-brand-300: #e1a288;
  --color-brand-400: #d47e5d;
  --color-brand-500: #c65d3b;
  --color-brand-600: #a8482c;
  --color-brand-700: #863824;
}

body {
  font-family: var(--font-body);
  color: var(--color-ink);
}
```

- [ ] **Step 4: Verify the build is clean**

Run: `cd frontend && npm run build`
Expected: build succeeds; font woff2 assets appear in the emitted `dist/assets/`. No unresolved-import or CSS errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/main.js frontend/src/assets/main.css
git commit -m "feat: marketing design foundation (warm-accent fonts + theme tokens)"
```

---

### Task 3: Marketing section components + LandingView

**Files:**
- Create: `frontend/src/components/marketing/MarketingNav.vue`
- Create: `frontend/src/components/marketing/HeroSection.vue`
- Create: `frontend/src/components/marketing/FeaturesSection.vue`
- Create: `frontend/src/components/marketing/HowItWorksSection.vue`
- Create: `frontend/src/components/marketing/PricingSection.vue`
- Create: `frontend/src/components/marketing/TestimonialsSection.vue`
- Create: `frontend/src/components/marketing/FaqSection.vue`
- Create: `frontend/src/components/marketing/ContactSection.vue`
- Create: `frontend/src/components/marketing/MarketingFooter.vue`
- Create: `frontend/src/views/LandingView.vue`
- Test gate: `npm run build`

**Interfaces:**
- Consumes: theme tokens from Task 2 (`font-display`, `font-body`, `bg-brand-*`, `text-ink`, `bg-paper`) and the backend `POST /api/contact` from Task 1.
- Produces: `LandingView.vue` — the default-exported component the router mounts at `/` in Task 4. Self-contained: renders the full marketing page from the section components. No props.
- Anchor contract: `MarketingNav` links scroll to in-page section ids `#features`, `#pricing`, `#faq`, `#contact`. Each corresponding section component's root element MUST carry that `id`.
- CTA contract: every "Register a salon" / "Get started" control navigates to `/register` (use `<RouterLink to="/register">`); "Login" navigates to `/login`.

**Design guidance (applies to all components in this task):** Use the **frontend-design** skill. Commit to the modern-SaaS-warm-accent direction from Task 2. Headlines in `font-display`; body in `font-body`. Light theme on `bg-paper`, warm charcoal `text-ink`, terracotta `brand-500/600` for primary actions and accents. Generous whitespace, soft shadows (`shadow-lg`/`shadow-xl` with warm tint), rounded cards (`rounded-2xl`), one tasteful staggered load on the hero. Illustration/product-mock is CSS/SVG only — no external images. Responsive (mobile-first; nav collapses on small screens). All copy below is the real, final content — do not substitute lorem ipsum.

- [ ] **Step 1: MarketingNav.vue**

Sticky top bar. Left: wordmark "SalonHub" in `font-display`. Center/right (desktop): anchor links **Features** (`#features`), **Pricing** (`#pricing`), **FAQ** (`#faq`), **Contact** (`#contact`). Far right: **Log in** (text link → `/login`) and **Register a salon** (primary terracotta button → `/register`). Mobile: collapse links into a toggle (`ref` open/close boolean); keep the primary CTA visible. Use `<RouterLink>` for `/login` and `/register`; use plain `<a href="#features">` for anchors.

- [ ] **Step 2: HeroSection.vue**

Root `<section>`. Headline (font-display, large): **"Run your salon. We'll run the booking."** Sub-copy: **"SalonHub gives your salon its own booking site, automatic reminders, deposits, and reviews — so your chair stays full and your front desk stays calm."** Two CTAs: **Register a salon** (primary → `/register`) and **Log in** (secondary/ghost → `/login`). Supporting visual: a CSS/SVG product mock — e.g. a stylized booking-calendar card with a few appointment chips, built from divs + the brand palette (no external asset). Add a subtle staggered entrance (CSS `animation-delay` on headline → subcopy → CTAs → visual).

- [ ] **Step 3: FeaturesSection.vue**

Root `<section id="features">`. Heading: **"Everything your salon needs, in one place."** Responsive grid (2 cols tablet, 3 cols desktop) of these 6 features — each an inline SVG icon + bold title + one line. Content (verbatim):

1. **Your own booking site** — "A branded page your clients book from 24/7, no app required."
2. **Automatic reminders** — "Email, SMS, and WhatsApp nudges that cut no-shows."
3. **Deposits & payments** — "Take a deposit at booking so serious clients hold their slot."
4. **Reviews that build trust** — "Collect post-visit reviews automatically and show them off."
5. **Multi-branch ready** — "Manage several locations, teams, and calendars from one login."
6. **Staff & schedules** — "Per-stylist availability, time off, and services — handled."

- [ ] **Step 4: HowItWorksSection.vue**

Root `<section>`. Heading: **"Live in three steps."** Three numbered steps (font-display numerals, brand accent):

1. **Register your salon** — "Create your account and claim your booking subdomain."
2. **Set up services & staff** — "Add what you offer, who does it, and when."
3. **Share your booking link** — "Drop it in your bio and start taking bookings."

- [ ] **Step 5: PricingSection.vue**

Root `<section id="pricing">`. Heading: **"Simple pricing that grows with you."** Three cards; middle (**Starter**) visually highlighted (brand border/shadow + "Most popular" badge). Each card: tier name (font-display), price, feature list (checkmark bullets), and a **Get started** button → `/register`. Content (verbatim, from spec):

| Tier | Price | Includes |
|---|---|---|
| **Free** | $0 | 1 branch · online booking page · email notifications · up to 50 bookings/mo |
| **Starter** | $19/mo | Everything in Free · SMS/WhatsApp reminders · payment deposits · unlimited bookings · customer reviews |
| **Business** | $49/mo | Everything in Starter · multi-branch · staff management · custom domain · priority support |

Render each "Includes" cell as a bulleted list (split on `·`). All three CTAs → `/register`.

- [ ] **Step 6: TestimonialsSection.vue**

Root `<section>`. Heading: **"Loved by salons like yours."** Three static quote cards (mark as illustrative social proof — fictional but realistic). Content (verbatim):

1. "We cut no-shows almost in half in the first month. The deposit feature alone paid for itself." — **Mara V., Bloom & Braid**
2. "My clients book at midnight now. I stopped playing phone tag and got my evenings back." — **Devan R., Sharp & Co. Barbers**
3. "Running three locations used to mean three calendars. Now it's one screen." — **Priya S., Lotus Spa Group**

- [ ] **Step 7: FaqSection.vue**

Root `<section id="faq">`. Heading: **"Questions, answered."** Accordion with local open/close state (a `ref` holding the open index; clicking a question toggles it). Five Q&As (verbatim):

1. **Do I need my own website?** — "No. SalonHub gives every salon its own booking page the moment you register."
2. **Can clients pay a deposit?** — "Yes — turn on deposits and clients pay to confirm their slot, cutting no-shows."
3. **Does it send reminders?** — "Automatic email, SMS, and WhatsApp reminders go out before each appointment."
4. **Can I manage more than one location?** — "Yes. The Business plan supports multiple branches, teams, and calendars from one login."
5. **How do I get started?** — "Register your salon, add your services and staff, and share your booking link. It takes minutes."

- [ ] **Step 8: ContactSection.vue**

Root `<section id="contact">`. Heading: **"Talk to us."** Two-column layout:
- **Left — contact form:** fields `name`, `email`, `message` (textarea). On submit, POST to the backend via the shared axios client. Import: `import api from '@/lib/api'` and call `await api.post('/contact', { name, email, message })`. Manage `sending`, `success`, `errors` refs. On success: hide the form, show **"Thanks — we'll be in touch soon."** On `422`: map `error.response.data.errors` to inline field errors. On `429`: show **"Too many messages — please try again shortly."** On any other error: show **"Couldn't send just now — please email us directly."**
- **Right — mailto fallback:** plain text **"Prefer email? Reach us at"** with a `<a href="mailto:hello@salonhub.com">hello@salonhub.com</a>` link. This always works regardless of endpoint state.

Note: `@/lib/api` is the existing axios instance (baseURL `/api`), so `api.post('/contact', …)` hits `POST /api/contact`.

- [ ] **Step 9: MarketingFooter.vue**

Root `<footer>`. Wordmark "SalonHub" (font-display) + one-line tagline **"Booking software for modern salons."** Nav links (anchors to `#features`, `#pricing`, `#faq`, `#contact`; RouterLinks to `/login`, `/register`). A `mailto:hello@salonhub.com` link. Copyright line: **"© 2026 SalonHub. All rights reserved."**

- [ ] **Step 10: LandingView.vue**

Compose the page. Root wrapper `bg-paper text-ink min-h-screen`. Import and stack, in order: `MarketingNav`, `HeroSection`, `FeaturesSection`, `HowItWorksSection`, `PricingSection`, `TestimonialsSection`, `FaqSection`, `ContactSection`, `MarketingFooter`. No props, no store access. `<script setup>`.

```vue
<script setup>
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import HeroSection from '@/components/marketing/HeroSection.vue'
import FeaturesSection from '@/components/marketing/FeaturesSection.vue'
import HowItWorksSection from '@/components/marketing/HowItWorksSection.vue'
import PricingSection from '@/components/marketing/PricingSection.vue'
import TestimonialsSection from '@/components/marketing/TestimonialsSection.vue'
import FaqSection from '@/components/marketing/FaqSection.vue'
import ContactSection from '@/components/marketing/ContactSection.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'
</script>

<template>
  <div class="bg-paper text-ink min-h-screen">
    <MarketingNav />
    <main>
      <HeroSection />
      <FeaturesSection />
      <HowItWorksSection />
      <PricingSection />
      <TestimonialsSection />
      <FaqSection />
      <ContactSection />
    </main>
    <MarketingFooter />
  </div>
</template>
```

- [ ] **Step 11: Verify the build**

Run: `cd frontend && npm run build`
Expected: build succeeds; `LandingView` and marketing chunks emitted. No unresolved imports, no Vue compiler errors.

- [ ] **Step 12: Commit**

```bash
git add frontend/src/components/marketing frontend/src/views/LandingView.vue
git commit -m "feat: marketing landing page sections and view"
```

---

### Task 4: Router restructure — landing at `/`, guard authed staff to dashboard

**Files:**
- Modify: `frontend/src/router/index.js`
- Test gate: `npm run build` + the manual routing checks below

**Interfaces:**
- Consumes: `LandingView.vue` (Task 3), `useAuthStore` (already imported).
- Produces: bare `/` renders `LandingView` for anonymous visitors and redirects authenticated staff to `/dashboard`; all existing staff and customer routes resolve unchanged.

- [ ] **Step 1: Import LandingView**

In `frontend/src/router/index.js`, add to the top-level view imports (alongside `import DashboardView from '../views/DashboardView.vue'` etc.):

```js
import LandingView from '../views/LandingView.vue'
```

- [ ] **Step 2: Add the public landing route BEFORE the DashboardLayout record**

The DashboardLayout record is the `{ path: '/', component: DashboardLayout, ... }` object (currently at line ~82). Insert this new route object immediately *before* it (after the `/book/:slug/manage/:token` route). Vue Router matches in declaration order, so this leaf claims bare `/`:

```js
    {
      // Public SaaS marketing home. Declared before the DashboardLayout record
      // so bare `/` renders the landing page, not the authenticated shell.
      path: '/',
      name: 'landing',
      component: LandingView,
    },
```

- [ ] **Step 3: Remove the DashboardLayout index redirect**

Inside the `{ path: '/', component: DashboardLayout, ... }` record's `children` array, delete this line (currently line ~86) so the shell no longer claims bare `/`:

```js
        { path: '', redirect: '/dashboard' },
```

Leave every other child (`dashboard`, `appointments`, `calendar`, `branches`, `services`, `staff`, `customers`, `reports`, `reviews`, `gallery`, `settings`) untouched — each keeps its own `meta: { requiresAuth: true }` and resolves via its non-empty `path`.

- [ ] **Step 4: Guard authenticated staff away from the marketing page**

In `router.beforeEach`, extend the existing authed-redirect block. Change:

```js
  if ((to.path === '/login' || to.path === '/register') && authStore.isAuthenticated) {
    return '/dashboard'
  }
```

to also cover the landing route:

```js
  if (
    (to.name === 'landing' || to.path === '/login' || to.path === '/register') &&
    authStore.isAuthenticated
  ) {
    return '/dashboard'
  }
```

- [ ] **Step 5: Verify the build**

Run: `cd frontend && npm run build`
Expected: build succeeds, no router errors.

- [ ] **Step 6: Manual routing verification (the one non-obvious risk)**

Start the dev server (`npm run dev`) and confirm all three:
- (a) Anonymous visit to `/` renders `LandingView` (marketing page), NOT a redirect to `/dashboard` or `/login`.
- (b) While logged in as staff, visiting `/` redirects to `/dashboard`.
- (c) Every staff route still resolves while logged in: `/dashboard`, `/appointments`, `/calendar`, `/branches`, `/services`, `/staff`, `/customers`, `/reports`, `/reviews`, `/gallery`, `/settings`; and `/account/login` + `/account` still work for customers.

Record the results in the task report.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/router/index.js
git commit -m "feat: route bare / to marketing landing, guard authed staff to dashboard"
```

---

### Task 5: Dashboard SubdomainBanner

**Files:**
- Create: `frontend/src/components/SubdomainBanner.vue`
- Modify: `frontend/src/views/DashboardView.vue` (mount the banner near the top)
- Test gate: `npm run build` + manual check

**Interfaces:**
- Consumes: `useAuthStore().organization` (already loaded on login / `fetchMe`). The org resource exposes `slug` (string) and `primary_domain` (string like `{slug}.salonhub.com`, always populated by `OrganizationResource`).
- Produces: `SubdomainBanner.vue` — a self-contained dashboard banner. No props.

**Behavior:**
- Displayed domain string: `organization.primary_domain`, falling back to `` `${organization.slug}.salonhub.com` `` if `primary_domain` is absent.
- **Copy** button copies that domain string to the clipboard (`navigator.clipboard.writeText`), with a brief "Copied!" confirmation.
- **Visit** target: in production (`import.meta.env.PROD`) → `` `https://${domain}` ``; in local dev → `` `/salon/${organization.slug}` `` (the path-based microsite, since the real subdomain is unresolvable on localhost). The displayed string stays the `{slug}.salonhub.com` domain in both.
- If `organization` is null or has neither `primary_domain` nor `slug`, render nothing (hide the banner rather than show a broken link).

- [ ] **Step 1: Create SubdomainBanner.vue**

Create `frontend/src/components/SubdomainBanner.vue`:

```vue
<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

// Always the shareable {slug}.salonhub.com string; prefer the resource's
// primary_domain, fall back to deriving it from the slug.
const domain = computed(() => {
  const org = authStore.organization
  if (!org) return null
  if (org.primary_domain) return org.primary_domain
  if (org.slug) return `${org.slug}.salonhub.com`
  return null
})

// Real subdomains don't resolve on localhost, so in dev we Visit the
// path-based microsite instead. The displayed string stays the domain.
const visitUrl = computed(() => {
  const org = authStore.organization
  if (!org) return null
  if (import.meta.env.PROD && domain.value) return `https://${domain.value}`
  if (org.slug) return `/salon/${org.slug}`
  return null
})

const copied = ref(false)
async function copy() {
  if (!domain.value) return
  await navigator.clipboard.writeText(domain.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}
</script>

<template>
  <div
    v-if="domain"
    class="mb-6 flex flex-col gap-3 rounded-2xl border border-brand-200 bg-brand-50 p-5 sm:flex-row sm:items-center sm:justify-between"
  >
    <div>
      <p class="font-display text-lg text-ink">Your booking site is live</p>
      <p class="mt-1 font-mono text-sm text-brand-700">{{ domain }}</p>
    </div>
    <div class="flex gap-2">
      <button
        type="button"
        class="rounded-lg border border-brand-300 px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-100"
        @click="copy"
      >
        {{ copied ? 'Copied!' : 'Copy' }}
      </button>
      <a
        :href="visitUrl"
        target="_blank"
        rel="noopener"
        class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
      >
        Visit
      </a>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Mount the banner on the dashboard**

In `frontend/src/views/DashboardView.vue`, import the component in `<script setup>`:

```js
import SubdomainBanner from '@/components/SubdomainBanner.vue'
```

and render `<SubdomainBanner />` as the first child inside the view's top-level content wrapper (above the existing dashboard heading / stat cards). Match the surrounding markup's existing container/padding — read the current template first and place it so it spans the same width as the other dashboard content.

- [ ] **Step 3: Verify the build**

Run: `cd frontend && npm run build`
Expected: build succeeds, no errors.

- [ ] **Step 4: Manual check**

With the dev server running and logged in as a salon owner, confirm on `/dashboard`:
- Banner shows `{slug}.salonhub.com`.
- **Copy** copies that string and flips to "Copied!" briefly.
- **Visit** opens `/salon/{slug}` in dev (the path-based microsite). Record results in the report.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/SubdomainBanner.vue frontend/src/views/DashboardView.vue
git commit -m "feat: dashboard banner surfacing the salon booking subdomain"
```

---

## Self-Review

**Spec coverage:**
- Public landing at `/` with nav/hero/features/how-it-works/pricing/testimonials/FAQ/contact/footer → Task 3 (all 9 components) + Task 4 (routing). ✅
- Register-a-salon + Login CTAs → Task 3 (nav + hero, all → `/register` / `/login`). ✅
- Pricing (3 static tiers, CTA → register) → Task 3 Step 5. ✅
- Post-auth subdomain surfaced → Task 5 (SubdomainBanner). ✅
- Public contact endpoint (rate-limited, not tenant-scoped, config-driven address) → Task 1. ✅
- Contact form + mailto fallback → Task 3 Step 8. ✅
- Router restructure without regressing staff/customer routes → Task 4 (+ manual checks a/b/c). ✅
- Design foundation (warm accent, non-slop fonts, self-hosted assets) → Task 2. ✅
- Global constraints (no secrets, throttle:5,1, not tenant-scoped, enum-aligned tiers) → Task 1 + constraints block. ✅

**Placeholder scan:** All copy (hero, features, pricing, testimonials, FAQ, footer) is final verbatim content, not lorem ipsum. Backend code is complete. Frontend section markup/styling is intentionally delegated to the implementer via the frontend-design skill with exact content, tokens, ids, and CTA targets specified — no "TBD"/"add later".

**Type/name consistency:** `config('mail.contact_address')` used identically in Task 1 config, controller, and test. `POST /api/contact` consistent between Task 1 (route) and Task 3 Step 8 (`api.post('/contact')`). Theme tokens defined in Task 2 (`font-display`, `font-body`, `brand-*`, `paper`, `ink`) match their usage in Tasks 3 & 5. `organization.primary_domain` / `organization.slug` match the `OrganizationResource` fields. Anchor ids (`#features`, `#pricing`, `#faq`, `#contact`) declared in nav (Step 1) match the section roots (Steps 3, 5, 7, 8).
