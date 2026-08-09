# Marketing Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the landing page's argument for a phone-first Bangladeshi salon owner — pain first, real product UI instead of icon cards, honest pre-launch proof — while keeping the existing editorial styling.

**Architecture:** `LandingView.vue` composes ten section components. Three new shared primitives (`SectionHeading`, `RuleList`, `MarketingCta`) absorb markup every section hand-rolls today. Four product mocks move into `marketing/mocks/` so section components stay readable. Copy lives inline in each SFC as `script setup` constants — one locale, one consumer.

**Tech Stack:** Vue 3 `script setup`, Vue Router, Tailwind CSS v4 (tokens in `frontend/src/assets/main.css`), Vitest + `@vue/test-utils`.

All commands run from `/Users/niteshdas/Projects/salon-hub/frontend`.

## Global Constraints

- **Brand name is `Glowhub`**, never `SalonHub`, in every string this page renders.
- **Currency symbol is `৳`** (U+09F3 BENGALI RUPEE SIGN), never `$`.
- **Do not rename `SalonHub` outside `src/components/marketing/`.** 26 other files contain it; that is a separate mechanical change.
- **Do not change `CONTACT_EMAIL` or `APP_DOMAIN`.** Both are env-driven and mirrored by backend config; their `salonhub.com` defaults are out of scope. Brand-name assertions are therefore **case-sensitive on `SalonHub`**.
- **Palette and type are fixed:** `font-display` (Fraunces) for headings, `font-body` (Manrope) for body, `paper` / `ink` / `brand-*` only. Never `accent-*` — that ramp is the per-tenant colour and marketing must not repaint per salon.
- **No image assets.** All product mocks are CSS and inline SVG.
- **Mobile-first.** Single column below `lg`; page padding `px-5` on phone, `lg:px-8`.
- **`MarketingNav` and `MarketingFooter` are shared** with `SalonSearchView.vue` and `components/legal/LegalPage.vue`. Changes to them must keep `SalonSearchView.spec.js` green.
- **Test conventions:** mock `vue-router` per the pattern in `src/views/SalonSearchView.spec.js`; call `setActivePinia(createPinia())` in `beforeEach` for anything that renders `MarketingNav` (it reads `useSessionLink()`).
- **Every task ends with a commit.** Run `npm run test:unit` before each commit; it must pass.

---

## File Structure

| Path | Responsibility |
| --- | --- |
| `src/components/marketing/SectionHeading.vue` | eyebrow rule + serif heading + optional lede |
| `src/components/marketing/RuleList.vue` | bordered term/text row list |
| `src/components/marketing/MarketingCta.vue` | primary/secondary router button |
| `src/components/marketing/mocks/BookingDayMock.vue` | hero day schedule |
| `src/components/marketing/mocks/SalonPageMock.vue` | tour 1 — public booking page |
| `src/components/marketing/mocks/RemindersMock.vue` | tour 2 — SMS/WhatsApp bubbles |
| `src/components/marketing/mocks/MoneyMock.vue` | tour 3 — advance + weekly revenue |
| `src/components/marketing/HeroSection.vue` | rewritten |
| `src/components/marketing/PainSection.vue` | new |
| `src/components/marketing/ProductTourSection.vue` | new, `id="features"` |
| `src/components/marketing/HowItWorksSection.vue` | copy only |
| `src/components/marketing/PricingSection.vue` | rewritten |
| `src/components/marketing/TrustSection.vue` | new |
| `src/components/marketing/FaqSection.vue` | copy only |
| `src/components/marketing/CtaSection.vue` | new |
| `src/components/marketing/StickyMobileCta.vue` | new, phone-only bottom bar |
| `src/components/marketing/MarketingNav.vue` | rewritten |
| `src/components/marketing/MarketingFooter.vue` | rewritten, absorbs the contact form |
| `src/views/LandingView.vue` | composition + section order |
| **deleted** | `FeaturesSection.vue`, `TestimonialsSection.vue`, `ContactSection.vue` |

---

### Task 1: Shared primitives

**Files:**
- Create: `src/components/marketing/SectionHeading.vue`
- Create: `src/components/marketing/RuleList.vue`
- Create: `src/components/marketing/MarketingCta.vue`
- Test: `src/components/marketing/SectionHeading.spec.js`, `src/components/marketing/RuleList.spec.js`, `src/components/marketing/MarketingCta.spec.js`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SectionHeading` props `{ eyebrow: String (required), title: String (required), lede: String = '', align: 'left' | 'center' = 'left' }`. Renders the title in an `<h2>`.
  - `RuleList` props `{ items: Array<{ term: String, text: String, strong?: Boolean }> (required) }`. Renders a `<dl>`; `strong: true` rows use `text-ink` and a `text-brand-600` term.
  - `MarketingCta` props `{ to: String (required), label: String (required), variant: 'primary' | 'secondary' = 'primary', block: Boolean = false }`. Renders a `RouterLink`; primary appends an arrow `<svg>`.

- [ ] **Step 1: Write the failing tests**

Create `src/components/marketing/SectionHeading.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SectionHeading from './SectionHeading.vue'

describe('SectionHeading', () => {
  it('renders the eyebrow and the title as an h2', () => {
    const wrapper = mount(SectionHeading, {
      props: { eyebrow: 'Pricing', title: '৳0. Everything above.' },
    })

    expect(wrapper.text()).toContain('Pricing')
    expect(wrapper.find('h2').text()).toBe('৳0. Everything above.')
  })

  it('omits the lede paragraph when there is nothing to say', () => {
    const wrapper = mount(SectionHeading, { props: { eyebrow: 'FAQ', title: 'Questions, answered.' } })

    expect(wrapper.find('p.lede').exists()).toBe(false)
  })

  it('renders the lede when given one', () => {
    const wrapper = mount(SectionHeading, {
      props: { eyebrow: 'What you get', title: 'Three things, done properly.', lede: 'No add-ons.' },
    })

    expect(wrapper.find('p.lede').text()).toBe('No add-ons.')
  })

  it('centres the block and closes the eyebrow rule when align is center', () => {
    const wrapper = mount(SectionHeading, {
      props: { eyebrow: 'How it works', title: 'Live in three steps.', align: 'center' },
    })

    expect(wrapper.classes()).toContain('text-center')
    expect(wrapper.findAll('[data-rule]')).toHaveLength(2)
  })
})
```

Create `src/components/marketing/RuleList.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import RuleList from './RuleList.vue'

const ITEMS = [
  { term: 'Today', text: 'You reply at 9am. She booked somewhere else.' },
  { term: 'Glowhub', text: 'She books herself.', strong: true },
]

describe('RuleList', () => {
  it('renders one row per item with its term and text', () => {
    const wrapper = mount(RuleList, { props: { items: ITEMS } })

    expect(wrapper.findAll('dt')).toHaveLength(2)
    expect(wrapper.findAll('dt')[0].text()).toBe('Today')
    expect(wrapper.findAll('dd')[1].text()).toBe('She books herself.')
  })

  it('marks the strong row so it reads as the answer, not another complaint', () => {
    const wrapper = mount(RuleList, { props: { items: ITEMS } })

    expect(wrapper.findAll('dt')[0].classes()).toContain('text-ink/40')
    expect(wrapper.findAll('dt')[1].classes()).toContain('text-brand-600')
  })
})
```

Create `src/components/marketing/MarketingCta.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import MarketingCta from './MarketingCta.vue'

describe('MarketingCta', () => {
  it('links to its destination and shows its label', () => {
    const wrapper = mount(MarketingCta, { props: { to: '/register', label: 'Register free' } })

    expect(wrapper.find('a').attributes('href')).toBe('/register')
    expect(wrapper.text()).toContain('Register free')
  })

  it('gives the primary variant the filled treatment and an arrow', () => {
    const wrapper = mount(MarketingCta, { props: { to: '/register', label: 'Register free' } })

    expect(wrapper.find('a').classes()).toContain('bg-brand-500')
    expect(wrapper.find('svg').exists()).toBe(true)
  })

  it('gives the secondary variant an outline and no arrow', () => {
    const wrapper = mount(MarketingCta, {
      props: { to: '/salon/demo-salon', label: 'See a live booking page', variant: 'secondary' },
    })

    expect(wrapper.find('a').classes()).not.toContain('bg-brand-500')
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('stretches full width when block is set, for the phone layout', () => {
    const wrapper = mount(MarketingCta, { props: { to: '/register', label: 'Register free', block: true } })

    expect(wrapper.find('a').classes()).toContain('w-full')
  })
})
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `npm run test:unit -- src/components/marketing`
Expected: FAIL — `Failed to resolve import "./SectionHeading.vue"` and the same for the other two.

- [ ] **Step 3: Write SectionHeading.vue**

```vue
<script setup>
// Every section used to hand-roll this eyebrow-rule + serif h2 pair, and the
// heading size had already drifted between them. Centred headings close the
// rule on both sides; left-aligned ones open it only on the left.
defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  lede: { type: String, default: '' },
  align: { type: String, default: 'left' },
})
</script>

<template>
  <div :class="['max-w-2xl', align === 'center' ? 'mx-auto text-center' : '']">
    <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
      <span data-rule class="h-px w-8 bg-brand-300"></span>
      {{ eyebrow }}
      <span v-if="align === 'center'" data-rule class="h-px w-8 bg-brand-300"></span>
    </p>
    <h2 class="mt-4 font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-ink sm:text-4xl lg:text-5xl">
      {{ title }}
    </h2>
    <p v-if="lede" class="lede mt-5 text-lg leading-relaxed text-ink/65">{{ lede }}</p>
  </div>
</template>
```

- [ ] **Step 4: Write RuleList.vue**

```vue
<script setup>
// The editorial rule-list: a term in the margin, a sentence beside it, a hair
// rule between. It carries both the pain band and the "also included" line, so
// `strong` exists to let one row read as the answer rather than another
// complaint.
defineProps({
  items: { type: Array, required: true },
})
</script>

<template>
  <dl class="border-t border-ink/10">
    <div
      v-for="item in items"
      :key="item.term + item.text"
      class="grid gap-1.5 border-b border-ink/10 py-5 sm:grid-cols-[8rem_1fr] sm:gap-8"
    >
      <dt
        :class="[
          'font-display text-sm tracking-wide uppercase',
          item.strong ? 'text-brand-600' : 'text-ink/40',
        ]"
      >
        {{ item.term }}
      </dt>
      <dd :class="['text-base leading-relaxed', item.strong ? 'text-ink' : 'text-ink/65']">
        {{ item.text }}
      </dd>
    </div>
  </dl>
</template>
```

- [ ] **Step 5: Write MarketingCta.vue**

```vue
<script setup>
// The hero, pricing card, closing band, nav and footer all shipped their own
// copy of this button and had already drifted on padding and shadow.
import { RouterLink } from 'vue-router'

defineProps({
  to: { type: String, required: true },
  label: { type: String, required: true },
  variant: { type: String, default: 'primary' },
  block: { type: Boolean, default: false },
})
</script>

<template>
  <RouterLink
    :to="to"
    :class="[
      'inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-7 py-3.5 text-base font-semibold transition-all duration-200 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:outline-none',
      block ? 'w-full' : '',
      variant === 'primary'
        ? 'bg-brand-500 text-white shadow-xl shadow-brand-500/25 hover:-translate-y-0.5 hover:bg-brand-600 hover:shadow-brand-500/35'
        : 'border border-brand-200 bg-white/60 text-brand-700 hover:border-brand-300 hover:bg-white',
    ]"
  >
    {{ label }}
    <svg
      v-if="variant === 'primary'"
      viewBox="0 0 24 24"
      class="h-4 w-4"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M5 12h14M13 6l6 6-6 6" />
    </svg>
  </RouterLink>
</template>
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `npm run test:unit -- src/components/marketing`
Expected: PASS — 11 tests across three files.

- [ ] **Step 7: Commit**

```bash
git add src/components/marketing/SectionHeading.vue src/components/marketing/SectionHeading.spec.js \
        src/components/marketing/RuleList.vue src/components/marketing/RuleList.spec.js \
        src/components/marketing/MarketingCta.vue src/components/marketing/MarketingCta.spec.js
git commit -m "feat(marketing): add the shared editorial primitives

Every section grew its own eyebrow rule, its own serif h2 and its own
button, and they had drifted on size, padding and shadow. Three small
components hold the shapes now so the redesign has something to build on."
```

---

### Task 2: Hero rewrite and the day-schedule mock

**Files:**
- Create: `src/components/marketing/mocks/BookingDayMock.vue`
- Rewrite: `src/components/marketing/HeroSection.vue`
- Test: `src/components/marketing/HeroSection.spec.js`

**Interfaces:**
- Consumes: `MarketingCta` (Task 1).
- Produces: `HeroSection` renders `<section id="top">`. `BookingDayMock` takes no props.

The hero's two floating cards currently use `-top-5 -right-3` and `-bottom-6 -left-3`, which push past a narrow viewport. They are replaced by a footer row **inside** the card, so nothing overflows at any width and there is no second branch to keep in sync.

- [ ] **Step 1: Write the failing test**

Create `src/components/marketing/HeroSection.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import HeroSection from './HeroSection.vue'

describe('HeroSection', () => {
  it('leads with the after-hours booking problem', () => {
    const wrapper = mount(HeroSection)

    expect(wrapper.find('h1').text()).toContain('11pm')
  })

  it('offers exactly one primary action, to registration', () => {
    const wrapper = mount(HeroSection)
    const primaries = wrapper.findAll('a').filter((a) => a.classes().includes('bg-brand-500'))

    expect(primaries).toHaveLength(1)
    expect(primaries[0].attributes('href')).toBe('/register')
  })

  it('sends the second action to the demo salon rather than to a signup', () => {
    const wrapper = mount(HeroSection)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).toContain('/salon/demo-salon')
  })

  it('prices the mock in taka and never in dollars', () => {
    const wrapper = mount(HeroSection)

    expect(wrapper.text()).toContain('৳')
    expect(wrapper.text()).not.toContain('$')
  })

  it('keeps the proof line inside the card, so nothing hangs off the viewport', () => {
    const wrapper = mount(HeroSection)
    const html = wrapper.html()

    expect(wrapper.text()).toContain('booked at 11:04pm')
    expect(html).not.toContain('-right-3')
    expect(html).not.toContain('-left-3')
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- src/components/marketing/HeroSection`
Expected: FAIL — the h1 still reads "Run your salon." and there is no `৳`.

- [ ] **Step 3: Write mocks/BookingDayMock.vue**

```vue
<script setup>
// The hero's proof: a day that filled itself. Pure CSS — no image assets,
// because the buyer is on a cheap Android over mobile data.
const appointments = [
  { time: '10:00', service: 'Cut & style', stylist: 'Dilruba', accent: 'bg-brand-400', status: 'Confirmed', chip: 'bg-brand-50 text-brand-700' },
  { time: '11:30', service: 'Balayage', stylist: 'Mira', accent: 'bg-rose-400', status: '৳500 advance', chip: 'bg-emerald-50 text-emerald-700' },
  { time: '14:00', service: 'Beard trim', stylist: 'Sam', accent: 'bg-brand-300', status: 'Confirmed', chip: 'bg-brand-50 text-brand-700' },
  { time: '16:30', service: 'Colour & gloss', stylist: 'Priya', accent: 'bg-brand-500', status: 'New', chip: 'bg-amber-50 text-amber-700' },
]
</script>

<template>
  <div class="rounded-3xl border border-brand-100 bg-white p-5 shadow-2xl shadow-ink/10 ring-1 ring-brand-50 sm:p-6">
    <div class="flex items-center justify-between gap-3">
      <div>
        <p class="text-xs font-medium tracking-wide text-ink/45 uppercase">Your booking page</p>
        <p class="mt-0.5 font-display text-lg font-semibold text-ink">Tuesday, 12 May</p>
      </div>
      <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700">
        <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
        6 booked
      </span>
    </div>

    <div class="mt-5 space-y-2.5">
      <div
        v-for="a in appointments"
        :key="a.time"
        class="flex items-center gap-3 rounded-2xl border border-brand-50 bg-paper/60 p-3"
      >
        <span class="w-11 shrink-0 font-display text-sm font-semibold text-ink/70">{{ a.time }}</span>
        <span :class="['h-9 w-1 shrink-0 rounded-full', a.accent]"></span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-ink">{{ a.service }}</p>
          <p class="truncate text-xs text-ink/50">with {{ a.stylist }}</p>
        </div>
        <span :class="['shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold', a.chip]">{{ a.status }}</span>
      </div>
    </div>

    <p class="mt-5 flex items-center gap-2 border-t border-brand-50 pt-4 text-xs text-ink/55">
      <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600">
        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      </span>
      Last one booked at 11:04pm, while you slept.
    </p>
  </div>
</template>
```

- [ ] **Step 4: Rewrite HeroSection.vue**

Replace the whole file:

```vue
<script setup>
import MarketingCta from './MarketingCta.vue'
import BookingDayMock from './mocks/BookingDayMock.vue'

// The seeded demo tenant (backend DemoSalonSeeder). If it is ever removed from
// production this must fall back to /salons — a dead link here is worse than a
// vaguer one.
const DEMO_SALON = '/salon/demo-salon'
</script>

<template>
  <section id="top" class="relative overflow-hidden">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
      <div class="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-brand-200/40 blur-3xl"></div>
      <div class="absolute top-40 -left-32 h-96 w-96 rounded-full bg-rose-200/30 blur-3xl"></div>
    </div>

    <div class="mx-auto grid max-w-6xl items-center gap-12 px-5 pt-14 pb-16 sm:pt-20 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:pt-28 lg:pb-24">
      <div class="max-w-xl">
        <p class="rise d0 inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white/70 px-3.5 py-1.5 text-xs font-semibold tracking-wide text-brand-700 uppercase">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
          Booking software for salons
        </p>

        <h1 class="rise d1 mt-6 font-display text-[2.25rem] leading-[1.05] font-semibold tracking-tight text-ink sm:text-5xl lg:text-6xl">
          Your next client is trying to book at 11pm.
        </h1>

        <p class="rise d2 mt-6 text-lg leading-relaxed text-ink/70">
          Glowhub gives your salon its own booking page — it takes appointments while you're closed, sends
          the reminder, and holds the slot with an advance. No more scrolling back through Messenger to find
          who's coming at 4.
        </p>

        <div class="rise d3 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
          <MarketingCta to="/register" label="Register free" />
          <MarketingCta :to="DEMO_SALON" label="See a live booking page" variant="secondary" />
        </div>

        <p class="rise d3 mt-6 text-sm text-ink/50">Free forever · No card · Live in 10 minutes</p>
      </div>

      <div class="rise d4 mx-auto w-full max-w-md lg:mx-0">
        <BookingDayMock />
      </div>
    </div>
  </section>
</template>

<style scoped>
@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(18px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.rise {
  animation: rise 0.7s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}

.d0 {
  animation-delay: 0.02s;
}
.d1 {
  animation-delay: 0.1s;
}
.d2 {
  animation-delay: 0.2s;
}
.d3 {
  animation-delay: 0.3s;
}
.d4 {
  animation-delay: 0.42s;
}

@media (prefers-reduced-motion: reduce) {
  .rise {
    animation: none;
  }
}
</style>
```

- [ ] **Step 5: Run the test and watch it pass**

Run: `npm run test:unit -- src/components/marketing/HeroSection`
Expected: PASS — 5 tests.

- [ ] **Step 6: Commit**

```bash
git add src/components/marketing/HeroSection.vue src/components/marketing/HeroSection.spec.js \
        src/components/marketing/mocks/BookingDayMock.vue
git commit -m "feat(marketing): lead the hero with the 11pm booking

\"Run your salon. We'll run the booking.\" describes the product without
naming anyone's problem. The buyer's problem is a client messaging at
midnight and going elsewhere by morning, so the headline says that and
the mock shows a day that filled itself.

The two floating cards are gone. Their negative offsets hung off the
right edge of a phone, and the one fact they carried now sits inside the
card where it cannot overflow."
```

---

### Task 3: Pain band

**Files:**
- Create: `src/components/marketing/PainSection.vue`
- Modify: `src/views/LandingView.vue`
- Test: `src/components/marketing/PainSection.spec.js`

**Interfaces:**
- Consumes: `RuleList` (Task 1).
- Produces: `PainSection` — no props, no id.

- [ ] **Step 1: Write the failing test**

Create `src/components/marketing/PainSection.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PainSection from './PainSection.vue'

describe('PainSection', () => {
  it('lists three of the problems the owner has today and one answer', () => {
    const wrapper = mount(PainSection)
    const terms = wrapper.findAll('dt').map((dt) => dt.text())

    expect(terms).toEqual(['Today', 'Today', 'Today', 'Glowhub'])
  })

  it('names the channels the owner actually loses bookings in', () => {
    const wrapper = mount(PainSection)

    expect(wrapper.text()).toContain('midnight')
    expect(wrapper.text()).toContain('reminded')
  })

  it('gives the answer row the emphasis treatment', () => {
    const wrapper = mount(PainSection)
    const answer = wrapper.findAll('dt').at(-1)

    expect(answer.classes()).toContain('text-brand-600')
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- src/components/marketing/PainSection`
Expected: FAIL — `Failed to resolve import "./PainSection.vue"`.

- [ ] **Step 3: Write PainSection.vue**

```vue
<script setup>
import RuleList from './RuleList.vue'

// Three losses the owner already recognises, then the same day with Glowhub.
// No feature is named until the next section — this band only has to make the
// reader feel seen.
const rows = [
  { term: 'Today', text: 'A client messages at midnight. You reply at 9am. She has booked somewhere else.' },
  { term: 'Today', text: 'Three appointments are confirmed, one shows up. Nobody was reminded.' },
  { term: 'Today', text: 'Someone asks the price of highlights. You type it out. Again.' },
  {
    term: 'Glowhub',
    text: 'She books herself, gets a reminder the day before, and the price was on the page.',
    strong: true,
  },
]
</script>

<template>
  <section class="bg-white py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <h2 class="font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-ink sm:text-4xl">
        The front desk you don't have.
      </h2>
      <div class="mt-8">
        <RuleList :items="rows" />
      </div>
    </div>
  </section>
</template>
```

- [ ] **Step 4: Insert it into LandingView.vue**

Add the import beside the others and render it directly after `<HeroSection />`:

```js
import PainSection from '@/components/marketing/PainSection.vue'
```

```html
      <HeroSection />
      <PainSection />
      <FeaturesSection />
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `npm run test:unit`
Expected: PASS — the new file plus everything already green.

- [ ] **Step 6: Commit**

```bash
git add src/components/marketing/PainSection.vue src/components/marketing/PainSection.spec.js src/views/LandingView.vue
git commit -m "feat(marketing): name the problem before selling the fix

The page went straight from the hero to a feature grid, so a reader who
had not already decided they needed booking software was never given a
reason to keep going. Three losses they recognise, then the same day with
Glowhub."
```

---

### Task 4: Product tour replaces the feature grid

**Files:**
- Create: `src/components/marketing/mocks/SalonPageMock.vue`
- Create: `src/components/marketing/mocks/RemindersMock.vue`
- Create: `src/components/marketing/mocks/MoneyMock.vue`
- Create: `src/components/marketing/ProductTourSection.vue`
- Delete: `src/components/marketing/FeaturesSection.vue`
- Modify: `src/views/LandingView.vue`
- Test: `src/components/marketing/ProductTourSection.spec.js`

**Interfaces:**
- Consumes: `SectionHeading`, `RuleList` (Task 1).
- Produces: `ProductTourSection` renders `<section id="features">`, which is the target of the nav and footer `#features` anchors. The three mocks take no props.

`SalonPageMock` shows the shareable host as `your-salon.{APP_DOMAIN}` read from `@/lib/tenantHost` — that is the exact shape `SubdomainBanner.vue` gives a real owner, so the promise here and the product agree. Do not hardcode a domain.

- [ ] **Step 1: Write the failing test**

Create `src/components/marketing/ProductTourSection.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ProductTourSection from './ProductTourSection.vue'

describe('ProductTourSection', () => {
  it('anchors the Features nav link', () => {
    const wrapper = mount(ProductTourSection)

    expect(wrapper.find('section').attributes('id')).toBe('features')
  })

  it('tells three numbered stories, not six icon cards', () => {
    const wrapper = mount(ProductTourSection)
    const headings = wrapper.findAll('h3').map((h) => h.text())

    expect(headings).toHaveLength(3)
    expect(headings[0]).toContain('A booking page of your own')
    expect(headings[1]).toContain('Reminders that get read')
    expect(headings[2]).toContain('Money you can see')
  })

  it('shows the shareable address in the shape an owner is actually given', () => {
    const wrapper = mount(ProductTourSection)

    expect(wrapper.text()).toContain('your-salon.')
  })

  it('sweeps the remaining features into one line rather than a second grid', () => {
    const wrapper = mount(ProductTourSection)
    const terms = wrapper.findAll('dt').map((dt) => dt.text())

    expect(terms).toEqual(['Also included'])
    expect(wrapper.find('dd').text()).toContain('Payroll')
  })

  it('reads text before mock on a phone and only alternates from lg up', () => {
    const wrapper = mount(ProductTourSection)
    const blocks = wrapper.findAll('[data-tour-block]')

    expect(blocks).toHaveLength(3)
    // The reversed block flips only at lg; source order stays text-then-mock.
    expect(blocks[1].html()).toContain('lg:order-2')
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- src/components/marketing/ProductTourSection`
Expected: FAIL — `Failed to resolve import "./ProductTourSection.vue"`.

- [ ] **Step 3: Write mocks/SalonPageMock.vue**

```vue
<script setup>
// The address an owner is actually handed is `{slug}.{APP_DOMAIN}` — the same
// string SubdomainBanner.vue shows them in the dashboard. Reading APP_DOMAIN
// here keeps the promise and the product from drifting apart per environment.
import { APP_DOMAIN } from '@/lib/tenantHost'

const services = [
  { name: 'Cut & style', meta: '45 min', price: '৳800' },
  { name: 'Balayage', meta: '2 hr', price: '৳4,500' },
  { name: 'Bridal makeup', meta: '90 min', price: '৳6,000' },
]
</script>

<template>
  <div class="mx-auto w-full max-w-xs rounded-[1.75rem] border border-brand-100 bg-white p-3 shadow-2xl shadow-ink/10">
    <p class="truncate rounded-full bg-paper px-3 py-1.5 text-center text-[11px] font-medium text-ink/45">
      your-salon.{{ APP_DOMAIN }}
    </p>
    <div class="mt-3 h-20 rounded-2xl bg-gradient-to-br from-brand-200 via-brand-100 to-rose-100"></div>
    <p class="mt-3 font-display text-lg font-semibold text-ink">Rupali Beauty Lounge</p>
    <p class="text-xs text-ink/50">Zindabazar, Sylhet · Open until 8pm</p>
    <ul class="mt-3 space-y-2">
      <li
        v-for="s in services"
        :key="s.name"
        class="flex items-center justify-between gap-2 rounded-xl border border-brand-50 bg-paper/60 px-3 py-2"
      >
        <span class="min-w-0">
          <span class="block truncate text-xs font-semibold text-ink">{{ s.name }}</span>
          <span class="block text-[10px] text-ink/45">{{ s.meta }}</span>
        </span>
        <span class="shrink-0 font-display text-sm font-semibold text-ink">{{ s.price }}</span>
      </li>
    </ul>
    <p class="mt-3 rounded-full bg-brand-500 py-2 text-center text-xs font-semibold text-white">Book now</p>
  </div>
</template>
```

- [ ] **Step 4: Write mocks/RemindersMock.vue**

```vue
<script setup>
// Two channels, one message. Both are real: AppointmentReminderService sends
// over Twilio SMS and WhatsApp from the salon's own account.
const messages = [
  {
    channel: 'SMS',
    tint: 'bg-brand-50 text-brand-700',
    body: 'Rupali Beauty Lounge: your Balayage is tomorrow at 11:30 with Mira. Reply CANCEL to cancel.',
    meta: 'Sent 18:00, day before',
  },
  {
    channel: 'WhatsApp',
    tint: 'bg-emerald-50 text-emerald-700',
    body: 'Reminder: Cut & style, tomorrow 10:00 with Dilruba. See you soon!',
    meta: 'Delivered · Read',
  },
]
</script>

<template>
  <div class="mx-auto w-full max-w-sm space-y-3">
    <div
      v-for="m in messages"
      :key="m.channel"
      class="rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-ink/[0.06]"
    >
      <span :class="['inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold', m.tint]">{{ m.channel }}</span>
      <p class="mt-3 text-sm leading-relaxed text-ink/80">{{ m.body }}</p>
      <p class="mt-3 text-[11px] text-ink/45">{{ m.meta }}</p>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Write mocks/MoneyMock.vue**

```vue
<script setup>
// Bars are fixed percentages, not data — this is a drawing of the reports
// page, and nothing here is presented as a measured result.
const week = [
  { day: 'Mon', height: 'h-8' },
  { day: 'Tue', height: 'h-14' },
  { day: 'Wed', height: 'h-10' },
  { day: 'Thu', height: 'h-20' },
  { day: 'Fri', height: 'h-24' },
  { day: 'Sat', height: 'h-16' },
]
</script>

<template>
  <div class="mx-auto w-full max-w-sm space-y-3">
    <div class="flex items-center gap-3 rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-ink/[0.06]">
      <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-600">
        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      </span>
      <span class="min-w-0">
        <span class="block text-sm font-semibold text-ink">৳500 advance received</span>
        <span class="block text-xs text-ink/50">Balayage · Tuesday 11:30 · card</span>
      </span>
    </div>

    <div class="rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-ink/[0.06]">
      <div class="flex items-baseline justify-between">
        <p class="text-xs font-medium tracking-wide text-ink/45 uppercase">This week</p>
        <p class="font-display text-xl font-semibold text-ink">৳48,200</p>
      </div>
      <div class="mt-4 flex items-end justify-between gap-2" aria-hidden="true">
        <span v-for="b in week" :key="b.day" class="flex flex-1 flex-col items-center gap-1.5">
          <span :class="['w-full rounded-t-md bg-brand-300', b.height]"></span>
          <span class="text-[10px] text-ink/40">{{ b.day }}</span>
        </span>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 6: Write ProductTourSection.vue**

```vue
<script setup>
import SectionHeading from './SectionHeading.vue'
import RuleList from './RuleList.vue'
import SalonPageMock from './mocks/SalonPageMock.vue'
import RemindersMock from './mocks/RemindersMock.vue'
import MoneyMock from './mocks/MoneyMock.vue'

// Three claims, each backed by shipped code: the tenant microsite,
// AppointmentReminderService over Twilio, and SslcommerzGateway + ReportService.
// Nothing here may promise something the API cannot do.
const blocks = [
  {
    n: '01',
    title: 'A booking page of your own',
    body: "Live the minute you register. Your services, your prices, your stylists, your photos. Share the link in your bio and clients book themselves.",
    mock: SalonPageMock,
  },
  {
    n: '02',
    title: 'Reminders that get read',
    body: 'An automatic SMS or WhatsApp the day before, from your own number. The single biggest thing you can do about no-shows.',
    mock: RemindersMock,
  },
  {
    n: '03',
    title: 'Money you can see',
    body: 'Take an advance at booking by card or mobile banking, and see what the week actually made — bookings, revenue, no-shows, staff.',
    mock: MoneyMock,
  },
]

const alsoIncluded = [
  {
    term: 'Also included',
    text: 'Staff schedules & time off · Reviews from real visits · Calendar · Customer list · Expenses · Payroll',
  },
]
</script>

<template>
  <section id="features" class="scroll-mt-24 py-16 sm:py-24">
    <div class="mx-auto max-w-6xl px-5 lg:px-8">
      <SectionHeading eyebrow="What you get" title="Three things, done properly." />

      <div class="mt-14 space-y-16 lg:space-y-24">
        <div
          v-for="(b, i) in blocks"
          :key="b.n"
          data-tour-block
          class="grid items-center gap-8 lg:grid-cols-2 lg:gap-16"
        >
          <div :class="i % 2 === 1 ? 'lg:order-2' : ''">
            <p class="font-display text-2xl font-semibold text-brand-400">{{ b.n }}</p>
            <h3 class="mt-2 font-display text-2xl font-semibold tracking-tight text-ink sm:text-3xl">
              {{ b.title }}
            </h3>
            <p class="mt-4 text-lg leading-relaxed text-ink/65">{{ b.body }}</p>
          </div>
          <div :class="i % 2 === 1 ? 'lg:order-1' : ''">
            <component :is="b.mock" />
          </div>
        </div>
      </div>

      <div class="mt-16">
        <RuleList :items="alsoIncluded" />
      </div>
    </div>
  </section>
</template>
```

- [ ] **Step 7: Swap it into LandingView.vue and delete the grid**

Replace the `FeaturesSection` import with:

```js
import ProductTourSection from '@/components/marketing/ProductTourSection.vue'
```

and the `<FeaturesSection />` element with `<ProductTourSection />`. Then:

```bash
git rm src/components/marketing/FeaturesSection.vue
```

- [ ] **Step 8: Run the tests and watch them pass**

Run: `npm run test:unit`
Expected: PASS. If anything still imports `FeaturesSection`, the run fails on resolution — fix the import rather than restoring the file.

- [ ] **Step 9: Commit**

```bash
git add src/components/marketing/ProductTourSection.vue src/components/marketing/ProductTourSection.spec.js \
        src/components/marketing/mocks src/views/LandingView.vue
git commit -m "feat(marketing): show the product instead of six icons

A grid of six line icons tells a reader the categories a product has, not
what using it looks like. Three blocks now carry the three claims that
matter, each next to a drawing of the actual screen, and the rest of the
feature list collapses into one line under a rule.

The booking address is read from APP_DOMAIN rather than written out, so
what the page promises and what SubdomainBanner hands an owner cannot
drift apart."
```

---

### Task 5: How it works, and ৳0 pricing

**Files:**
- Modify: `src/components/marketing/HowItWorksSection.vue`
- Rewrite: `src/components/marketing/PricingSection.vue`
- Test: `src/components/marketing/PricingSection.spec.js`

**Interfaces:**
- Consumes: `SectionHeading`, `MarketingCta` (Task 1).
- Produces: `PricingSection` renders `<section id="pricing">`.

The limits stated here are exactly what `App\Services\PlanLimit` enforces (`FREE_MAX_BRANCHES = 1`, `FREE_MAX_STAFF = 10`). This card must never promise more than the API holds.

- [ ] **Step 1: Write the failing test**

Create `src/components/marketing/PricingSection.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import PricingSection from './PricingSection.vue'

describe('PricingSection', () => {
  it('prices in taka, never in dollars', () => {
    const wrapper = mount(PricingSection)

    expect(wrapper.text()).toContain('৳0')
    expect(wrapper.text()).not.toContain('$')
  })

  it('states the free limits the API actually enforces', () => {
    const wrapper = mount(PricingSection)

    expect(wrapper.text()).toContain('1 branch')
    expect(wrapper.text()).toContain('10 staff')
  })

  it('discloses who bills for payments and for reminders', () => {
    const wrapper = mount(PricingSection)

    expect(wrapper.text()).toContain('SSLCommerz')
    expect(wrapper.text()).toContain('Twilio')
  })

  it('sends its one action to registration', () => {
    const wrapper = mount(PricingSection)
    const primaries = wrapper.findAll('a').filter((a) => a.classes().includes('bg-brand-500'))

    expect(primaries).toHaveLength(1)
    expect(primaries[0].attributes('href')).toBe('/register')
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- src/components/marketing/PricingSection`
Expected: FAIL — the card still reads `$0`.

- [ ] **Step 3: Rewrite PricingSection.vue**

```vue
<script setup>
import SectionHeading from './SectionHeading.vue'
import MarketingCta from './MarketingCta.vue'

// v1 ships Free-only: no billing, no subscription state machine, no upgrade
// path exists yet. These limits are exactly what PlanLimit enforces
// (App\Services\PlanLimit::FREE_MAX_BRANCHES / FREE_MAX_STAFF) — this card
// must never promise more than the API actually holds.
const includes = [
  '1 branch',
  '10 staff',
  'Unlimited services',
  'Unlimited clients',
  'Your own booking page',
  'Calendar & appointments',
  'SMS & WhatsApp reminders',
  'Advance payments',
  'Reviews',
  'Reports, expenses & payroll',
]
</script>

<template>
  <section id="pricing" class="scroll-mt-24 bg-white py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <SectionHeading
        eyebrow="Pricing"
        title="৳0. Everything above."
        lede="Not a trial — the free plan is the product. Paid plans arrive when salons need more branches, and you will hear from us long before anything changes."
      />

      <ul class="mt-10 grid gap-x-8 gap-y-3.5 sm:grid-cols-2">
        <li v-for="item in includes" :key="item" class="flex items-start gap-3">
          <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-brand-500 text-white">
            <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 6 9 17l-5-5" />
            </svg>
          </span>
          <span class="text-base leading-relaxed text-ink/75">{{ item }}</span>
        </li>
      </ul>

      <div class="mt-10">
        <MarketingCta to="/register" label="Register free" />
      </div>

      <p class="mt-8 border-t border-ink/10 pt-6 text-sm leading-relaxed text-ink/50">
        Card and mobile-banking advances run through SSLCommerz. SMS and WhatsApp reminders use your own
        Twilio account and are billed by Twilio, not by us.
      </p>
    </div>
  </section>
</template>
```

- [ ] **Step 4: Tighten HowItWorksSection.vue**

Replace only the `steps` array and the heading block. The `steps` constant becomes:

```js
const steps = [
  { n: '01', title: 'Register your salon', body: 'Two minutes. Name, phone, and your booking address is yours.' },
  { n: '02', title: 'Add services & staff', body: 'Five minutes, guided. What you offer, who does it, and when.' },
  { n: '03', title: 'Share your link', body: 'Bio, WhatsApp status, shop window. Bookings start arriving.' },
]
```

Then replace the hand-rolled heading block — the `<p class="inline-flex …">How it works</p>` and the `<h2>` beneath it — with the primitive, importing it at the top of `<script setup>`:

```js
import SectionHeading from './SectionHeading.vue'
```

```html
      <SectionHeading eyebrow="How it works" title="Live in three steps." align="center" />
```

Change the section's outer padding from `py-20 sm:py-28` to `py-16 sm:py-24`, and the container's `px-6` to `px-5`.

- [ ] **Step 5: Run the tests and watch them pass**

Run: `npm run test:unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/components/marketing/PricingSection.vue src/components/marketing/PricingSection.spec.js \
        src/components/marketing/HowItWorksSection.vue
git commit -m "feat(marketing): price in taka and say who bills for what

A salon owner in Sylhet reading \$0 has to convert a number that was
never in their currency. The card is in taka now, lists what Free
actually holds, and states plainly that Twilio bills for reminders and
SSLCommerz handles advances — friction at the top of the funnel is
cheaper than the same surprise during setup.

The second \"more plans coming\" card goes: an empty box beside the only
plan drew the eye to the thing you cannot buy."
```

---

### Task 6: Honest proof, and an objection-shaped FAQ

**Files:**
- Create: `src/components/marketing/TrustSection.vue`
- Delete: `src/components/marketing/TestimonialsSection.vue`
- Modify: `src/components/marketing/FaqSection.vue`
- Modify: `src/views/LandingView.vue`
- Test: `src/components/marketing/TrustSection.spec.js`, `src/components/marketing/FaqSection.spec.js`

**Interfaces:**
- Consumes: `MarketingCta` (Task 1).
- Produces: `TrustSection` — no props. `FaqSection` keeps `id="faq"` and its existing accordion behaviour (`openIndex` starts at `0`).

- [ ] **Step 1: Write the failing tests**

Create `src/components/marketing/TrustSection.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import TrustSection from './TrustSection.vue'

describe('TrustSection', () => {
  it('says plainly that the product is new', () => {
    const wrapper = mount(TrustSection)

    expect(wrapper.find('h2').text()).toContain("We're new")
  })

  it('makes no claim that needs a customer to substantiate it', () => {
    const wrapper = mount(TrustSection)
    const text = wrapper.text()

    expect(text).not.toMatch(/\d+%/)
    expect(text).not.toContain('Loved by')
  })

  it('promises the client list is portable, which is the real objection', () => {
    const wrapper = mount(TrustSection)

    expect(wrapper.text()).toContain('Export it any time')
  })

  it('points at the demo salon rather than at a testimonial', () => {
    const wrapper = mount(TrustSection)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).toContain('/salon/demo-salon')
  })
})
```

Create `src/components/marketing/FaqSection.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import FaqSection from './FaqSection.vue'

describe('FaqSection', () => {
  it('answers the objections a Messenger-run salon actually has', () => {
    const wrapper = mount(FaqSection)
    const questions = wrapper.findAll('button').map((b) => b.text())

    expect(questions.some((q) => q.includes('Messenger'))).toBe(true)
    expect(questions.some((q) => q.includes('client list'))).toBe(true)
    expect(questions.some((q) => q.includes('free'))).toBe(true)
  })

  it('never calls the product by its old name', () => {
    const wrapper = mount(FaqSection)

    expect(wrapper.text()).not.toContain('SalonHub')
    expect(wrapper.text()).toContain('Glowhub')
  })

  it('opens the first answer and closes it again on a second click', async () => {
    const wrapper = mount(FaqSection)
    const first = wrapper.findAll('button')[0]

    expect(first.attributes('aria-expanded')).toBe('true')
    await first.trigger('click')
    expect(first.attributes('aria-expanded')).toBe('false')
  })
})
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npm run test:unit -- src/components/marketing/TrustSection src/components/marketing/FaqSection`
Expected: FAIL — `TrustSection.vue` does not resolve, and the FAQ has no Messenger question.

- [ ] **Step 3: Write TrustSection.vue**

```vue
<script setup>
import MarketingCta from './MarketingCta.vue'

// This replaces three testimonials we wrote ourselves. Nothing is live yet, so
// there is no honest version of social proof — the next best thing is saying
// so, and answering the questions a first customer would actually ask.
const promises = [
  {
    title: 'No fake reviews here.',
    body: "We won't show you testimonials we don't have. When real salons have something to say, you'll see their names.",
  },
  {
    title: 'Built in Bangladesh, for salons here.',
    body: 'Taka, local card and mobile banking, and the way your clients actually message you.',
  },
  {
    title: 'Your client list is yours.',
    body: 'Export it any time, in full. Leave any time. Nothing is held hostage.',
  },
  {
    title: 'You can reach a human.',
    body: 'Reply to any email from us and a founder answers. There is no ticket queue.',
  },
]
</script>

<template>
  <section class="bg-white py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <h2 class="font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-ink sm:text-4xl">
        We're new. Here's what that means.
      </h2>

      <dl class="mt-10 space-y-7">
        <div v-for="p in promises" :key="p.title">
          <dt class="font-display text-lg font-semibold text-ink">{{ p.title }}</dt>
          <dd class="mt-1.5 leading-relaxed text-ink/65">{{ p.body }}</dd>
        </div>
      </dl>

      <div class="mt-10">
        <MarketingCta to="/salon/demo-salon" label="See a real booking page" variant="secondary" />
      </div>
    </div>
  </section>
</template>
```

- [ ] **Step 4: Rewrite the FAQ entries**

In `src/components/marketing/FaqSection.vue`, replace the `faqs` array with:

```js
const faqs = [
  {
    q: 'My clients only use Messenger — will they use this?',
    a: "They don't need an account or an app. You send them a link, they pick a time, they're booked. Most people book in under a minute.",
  },
  {
    q: 'Do I need a website already?',
    a: 'No. Glowhub gives every salon its own booking page the moment you register.',
  },
  {
    q: 'Do I need a card to sign up?',
    a: 'No. The free plan needs no card, and there is nothing to cancel.',
  },
  {
    q: 'Can I take an advance payment?',
    a: 'Yes. Turn on advances and a client pays part of the price to hold the slot, by card or mobile banking through SSLCommerz.',
  },
  {
    q: 'What happens when it stops being free?',
    a: "It doesn't. Paid plans will add more branches and staff; what you can do today on Free stays free, and we will tell you before anything changes.",
  },
  {
    q: 'Who owns my client list?',
    a: 'You do. Export every client, booking and note whenever you want, and take it with you if you leave.',
  },
  {
    q: 'Can I run more than one branch?',
    a: 'One branch and up to ten staff on Free today. More branches are what the paid plans will be for.',
  },
]
```

Also swap the hand-rolled heading block for the primitive, as in Task 5:

```js
import SectionHeading from './SectionHeading.vue'
```

```html
      <SectionHeading eyebrow="FAQ" title="Questions, answered." align="center" />
```

Change the section padding to `py-16 sm:py-24` and the container `px-6` to `px-5`.

- [ ] **Step 5: Swap the section in LandingView.vue and delete the testimonials**

Replace the `TestimonialsSection` import with:

```js
import TrustSection from '@/components/marketing/TrustSection.vue'
```

and `<TestimonialsSection />` with `<TrustSection />`. Then:

```bash
git rm src/components/marketing/TestimonialsSection.vue
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `npm run test:unit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/components/marketing/TrustSection.vue src/components/marketing/TrustSection.spec.js \
        src/components/marketing/FaqSection.vue src/components/marketing/FaqSection.spec.js \
        src/views/LandingView.vue
git commit -m "feat(marketing): replace invented testimonials with the truth

Three quotes from salons that do not exist, under a heading claiming the
product is loved. Nothing is live yet, so there is no honest version of
that section — what replaces it says the product is new and answers the
questions a first customer would actually ask, starting with who owns
their client list.

The FAQ moves from feature trivia to the objections a Messenger-run
parlour has."
```

---

### Task 7: Closing band and the phone CTA bar

**Files:**
- Create: `src/components/marketing/CtaSection.vue`
- Create: `src/components/marketing/StickyMobileCta.vue`
- Modify: `src/views/LandingView.vue`
- Test: `src/components/marketing/StickyMobileCta.spec.js`

**Interfaces:**
- Consumes: `MarketingCta` (Task 1), `useSessionLink` from `@/lib/sessionLink`.
- Produces: `CtaSection` — no props. `StickyMobileCta` — no props; renders nothing when a session exists, and observes `#top` via `IntersectionObserver`, showing the bar once the hero has left the viewport.

`StickyMobileCta` needs an active Pinia (it calls `useSessionLink()`), and jsdom has no `IntersectionObserver` — the test installs a stub that captures the callback so visibility can be driven directly.

- [ ] **Step 1: Write the failing test**

Create `src/components/marketing/StickyMobileCta.spec.js`:

```js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import { TOKEN_KEY } from '@/lib/api'
import StickyMobileCta from './StickyMobileCta.vue'

// jsdom ships no IntersectionObserver. This stub keeps the last callback so a
// test can say "the hero just scrolled away" without any scrolling.
let fire
const observe = vi.fn()
const disconnect = vi.fn()

beforeEach(() => {
  // The stores read their token from localStorage at construction, so a
  // session is installed the way sessionLink.spec.js installs one.
  localStorage.clear()
  setActivePinia(createPinia())
  observe.mockClear()
  disconnect.mockClear()
  document.body.innerHTML = '<div id="top"></div>'
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      constructor(cb) {
        fire = cb
      }
      observe = observe
      disconnect = disconnect
    },
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('StickyMobileCta', () => {
  it('stays hidden while the hero is still on screen', () => {
    const wrapper = mount(StickyMobileCta)

    fire([{ isIntersecting: true }])
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('appears once the hero has scrolled away, pointing at registration', async () => {
    const wrapper = mount(StickyMobileCta)

    fire([{ isIntersecting: false }])
    await wrapper.vm.$nextTick()

    expect(wrapper.find('a').attributes('href')).toBe('/register')
  })

  it('is a phone-only device', async () => {
    const wrapper = mount(StickyMobileCta)

    fire([{ isIntersecting: false }])
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-sticky-cta]').classes()).toContain('lg:hidden')
  })

  it('never nags a visitor who is already signed in', async () => {
    localStorage.setItem(TOKEN_KEY, 'staff-token')
    setActivePinia(createPinia())

    const wrapper = mount(StickyMobileCta)
    fire?.([{ isIntersecting: false }])
    await wrapper.vm.$nextTick()

    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('stops observing when it goes away', () => {
    const wrapper = mount(StickyMobileCta)

    wrapper.unmount()
    expect(disconnect).toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- src/components/marketing/StickyMobileCta`
Expected: FAIL — `Failed to resolve import "./StickyMobileCta.vue"`.

- [ ] **Step 3: Write CtaSection.vue**

```vue
<script setup>
import MarketingCta from './MarketingCta.vue'
</script>

<template>
  <section class="bg-ink py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 text-center lg:px-8">
      <h2 class="font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-paper sm:text-4xl lg:text-5xl">
        Your booking page is ten minutes away.
      </h2>
      <p class="mt-5 text-lg leading-relaxed text-paper/60">
        Free to start. No card. Nothing to install.
      </p>
      <div class="mt-9 flex justify-center">
        <MarketingCta to="/register" label="Register your salon" />
      </div>
    </div>
  </section>
</template>
```

- [ ] **Step 4: Write StickyMobileCta.vue**

```vue
<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import MarketingCta from './MarketingCta.vue'
import { useSessionLink } from '@/lib/sessionLink'

// The buyer reads this page on a phone, where the hero's button is a long way
// up by the time they are convinced. The bar follows them down — but only for
// visitors with nothing to sign in to, because asking an owner who is already
// running a salon to register again is noise.
const session = useSessionLink()

const past = ref(false)
let observer = null

onMounted(() => {
  const hero = document.getElementById('top')
  if (!hero || typeof IntersectionObserver === 'undefined') return

  observer = new IntersectionObserver(([entry]) => {
    past.value = !entry.isIntersecting
  })
  observer.observe(hero)
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-full"
    enter-to-class="translate-y-0"
    leave-active-class="transition duration-150 ease-in"
    leave-from-class="translate-y-0"
    leave-to-class="translate-y-full"
  >
    <div
      v-if="past && !session"
      data-sticky-cta
      class="fixed inset-x-0 bottom-0 z-40 border-t border-brand-100 bg-paper/95 px-5 py-3 backdrop-blur-md lg:hidden"
    >
      <MarketingCta to="/register" label="Register free" block />
    </div>
  </Transition>
</template>
```

- [ ] **Step 5: Add both to LandingView.vue**

Import both, render `<CtaSection />` as the last element inside `<main>`, and `<StickyMobileCta />` as the last element inside the root `<div>`, after `<MarketingFooter />`:

```js
import CtaSection from '@/components/marketing/CtaSection.vue'
import StickyMobileCta from '@/components/marketing/StickyMobileCta.vue'
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `npm run test:unit`
Expected: PASS — 5 new tests.

- [ ] **Step 7: Commit**

```bash
git add src/components/marketing/CtaSection.vue src/components/marketing/StickyMobileCta.vue \
        src/components/marketing/StickyMobileCta.spec.js src/views/LandingView.vue
git commit -m "feat(marketing): close the page and follow the phone down

A reader who got all the way to the footer had to scroll back up to act.
The page now ends by asking, and on a phone a bar carries the same ask
down the page once the hero is gone. Signed-in visitors never see it —
they have nothing to register."
```

---

### Task 8: Nav and footer

**Files:**
- Modify: `src/components/marketing/MarketingNav.vue`
- Rewrite: `src/components/marketing/MarketingFooter.vue`
- Delete: `src/components/marketing/ContactSection.vue`
- Modify: `src/views/LandingView.vue`
- Test: `src/components/marketing/MarketingNav.spec.js`, `src/components/marketing/MarketingFooter.spec.js`

**Interfaces:**
- Consumes: `useSessionLink`, `CONTACT_EMAIL` from `@/lib/contact`, `api` from `@/lib/api`.
- Produces: `MarketingFooter` owns the contact form and posts the same `{ name, email, message }` body to `POST /contact` that `ContactSection` posted, with the same 422 / 429 / other handling. Both components are shared with `SalonSearchView.vue` and `components/legal/LegalPage.vue`.

The `#contact` anchor disappears from both link lists — there is no contact section to jump to any more.

- [ ] **Step 1: Write the failing tests**

Create `src/components/marketing/MarketingNav.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import MarketingNav from './MarketingNav.vue'

describe('MarketingNav', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('calls the product Glowhub', () => {
    const wrapper = mount(MarketingNav)

    expect(wrapper.text()).toContain('Glowhub')
    expect(wrapper.text()).not.toContain('SalonHub')
  })

  it('no longer offers an anchor to a section that does not exist', () => {
    const wrapper = mount(MarketingNav)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).not.toContain('#contact')
    expect(hrefs).toContain('#features')
  })

  it('keeps the customer entrances, demoted', () => {
    const wrapper = mount(MarketingNav)
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(hrefs).toContain('/salons')
    expect(hrefs).toContain('/account/login')
  })

  it('offers registration as the one filled action', () => {
    const wrapper = mount(MarketingNav)
    const filled = wrapper.findAll('a').filter((a) => a.classes().includes('bg-brand-500'))

    expect(filled.every((a) => a.attributes('href') === '/register')).toBe(true)
  })

  it('opens and closes the phone menu', async () => {
    const wrapper = mount(MarketingNav)
    const toggle = wrapper.find('button[aria-label="Toggle navigation menu"]')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })
})
```

Create `src/components/marketing/MarketingFooter.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))
vi.mock('@/lib/api', () => ({ default: { post: vi.fn() } }))

import api from '@/lib/api'
import MarketingFooter from './MarketingFooter.vue'

const fill = async (wrapper) => {
  await wrapper.find('#footer-contact-name').setValue('Rupali')
  await wrapper.find('#footer-contact-email').setValue('rupali@salon.test')
  await wrapper.find('#footer-contact-message').setValue('Do you support two branches?')
}

describe('MarketingFooter', () => {
  beforeEach(() => vi.mocked(api.post).mockReset())

  it('calls the product Glowhub', () => {
    const wrapper = mount(MarketingFooter)

    expect(wrapper.text()).toContain('Glowhub')
    expect(wrapper.text()).not.toContain('SalonHub')
  })

  it('sends the message to the contact endpoint', async () => {
    vi.mocked(api.post).mockResolvedValue({})
    const wrapper = mount(MarketingFooter)

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/contact', {
      name: 'Rupali',
      email: 'rupali@salon.test',
      message: 'Do you support two branches?',
    })
    expect(wrapper.text()).toContain("Thanks — we'll be in touch")
  })

  it('shows the field errors the server returns', async () => {
    vi.mocked(api.post).mockRejectedValue({ response: { status: 422, data: { errors: { email: ['Not an email.'] } } } })
    const wrapper = mount(MarketingFooter)

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Not an email.')
  })

  it('says so plainly when the visitor is rate limited', async () => {
    vi.mocked(api.post).mockRejectedValue({ response: { status: 429 } })
    const wrapper = mount(MarketingFooter)

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Too many messages')
  })
})
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npm run test:unit -- src/components/marketing/MarketingNav src/components/marketing/MarketingFooter`
Expected: FAIL — both still render `SalonHub`, and the footer has no form.

- [ ] **Step 3: Update MarketingNav.vue**

Four edits, nothing structural — the sticky behaviour, the session branch and the mobile panel all stay as they are:

1. `links` drops the Contact entry and gains How it works:

```js
const links = [
  { label: 'Features', href: '#features' },
  { label: 'How it works', href: '#how-it-works' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'FAQ', href: '#faq' },
]
```

2. The wordmark: `aria-label="Glowhub home"`, the monogram letter `S` becomes `G`, and the text `SalonHub` becomes `Glowhub`.
3. Both `Register a salon` labels (desktop actions and mobile bar) become `Register free`.
4. Add `min-h-11` to the class list of each desktop anchor link and to the `Salon log in` link, so every tap target clears 44px.

Then add `id="how-it-works"` and `class="scroll-mt-24"` to the `<section>` in `HowItWorksSection.vue` so the new anchor lands somewhere.

- [ ] **Step 4: Rewrite MarketingFooter.vue**

```vue
<script setup>
import { reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { CONTACT_EMAIL } from '@/lib/contact'
import api from '@/lib/api'

// The contact form used to own a full-height section between the FAQ and the
// footer, which put a form in front of a reader on their way to the closing
// ask. It lives here now, at the bottom, where someone who wants to write to
// us is already looking.
const productLinks = [
  { label: 'Features', href: '#features' },
  { label: 'How it works', href: '#how-it-works' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'FAQ', href: '#faq' },
]

const form = reactive({ name: '', email: '', message: '' })
const sending = ref(false)
const success = ref(false)
const errors = ref({})
const formError = ref('')

function fieldError(field) {
  const e = errors.value?.[field]
  return Array.isArray(e) ? e[0] : e
}

async function submit() {
  if (sending.value) return
  sending.value = true
  errors.value = {}
  formError.value = ''
  try {
    await api.post('/contact', { name: form.name, email: form.email, message: form.message })
    success.value = true
  } catch (e) {
    const status = e.response?.status
    if (status === 422) {
      errors.value = e.response?.data?.errors || {}
    } else if (status === 429) {
      formError.value = 'Too many messages — please try again shortly.'
    } else {
      formError.value = "Couldn't send just now — please email us directly."
    }
  } finally {
    sending.value = false
  }
}

const fieldClass = (field) => [
  'mt-1.5 w-full rounded-xl border bg-white/5 px-4 py-3 text-paper placeholder-paper/30 transition-colors focus:ring-2 focus:ring-brand-400/40 focus:outline-none',
  fieldError(field) ? 'border-rose-400' : 'border-paper/15 focus:border-brand-400',
]
</script>

<template>
  <footer class="bg-ink text-paper/70">
    <div class="mx-auto max-w-6xl px-5 py-14 lg:px-8 lg:py-16">
      <div class="grid gap-12 lg:grid-cols-[1.1fr_1fr]">
        <!-- Brand + write to us -->
        <div>
          <div class="flex items-center gap-2.5">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white">
              <span class="font-display text-lg leading-none font-semibold">G</span>
            </span>
            <span class="font-display text-xl font-semibold text-paper">Glowhub</span>
          </div>
          <p class="mt-4 max-w-xs leading-relaxed text-paper/55">Booking software for salons in Bangladesh.</p>
          <a
            :href="`mailto:${CONTACT_EMAIL}`"
            class="mt-4 inline-block text-sm text-paper/70 underline decoration-paper/20 underline-offset-4 transition-colors hover:text-paper hover:decoration-paper/50"
          >
            {{ CONTACT_EMAIL }}
          </a>

          <div v-if="success" class="mt-8 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-5">
            <p class="font-display text-lg font-semibold text-paper">Thanks — we'll be in touch soon.</p>
          </div>

          <form v-else class="mt-8 max-w-sm space-y-4" novalidate @submit.prevent="submit">
            <div>
              <label for="footer-contact-name" class="block text-sm font-medium text-paper/70">Name</label>
              <input
                id="footer-contact-name"
                v-model="form.name"
                type="text"
                autocomplete="name"
                :aria-invalid="!!fieldError('name')"
                :class="fieldClass('name')"
                placeholder="Your name"
              />
              <p v-if="fieldError('name')" class="mt-1.5 text-sm text-rose-300">{{ fieldError('name') }}</p>
            </div>

            <div>
              <label for="footer-contact-email" class="block text-sm font-medium text-paper/70">Email</label>
              <input
                id="footer-contact-email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                :aria-invalid="!!fieldError('email')"
                :class="fieldClass('email')"
                placeholder="you@salon.com"
              />
              <p v-if="fieldError('email')" class="mt-1.5 text-sm text-rose-300">{{ fieldError('email') }}</p>
            </div>

            <div>
              <label for="footer-contact-message" class="block text-sm font-medium text-paper/70">Message</label>
              <textarea
                id="footer-contact-message"
                v-model="form.message"
                rows="3"
                :aria-invalid="!!fieldError('message')"
                :class="fieldClass('message')"
                placeholder="How can we help your salon?"
              ></textarea>
              <p v-if="fieldError('message')" class="mt-1.5 text-sm text-rose-300">{{ fieldError('message') }}</p>
            </div>

            <p v-if="formError" class="rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
              {{ formError }}
            </p>

            <button
              type="submit"
              :disabled="sending"
              class="inline-flex min-h-11 items-center justify-center rounded-full bg-brand-500 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-brand-600 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-ink focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
            >
              {{ sending ? 'Sending…' : 'Send message' }}
            </button>
          </form>
        </div>

        <!-- Link columns -->
        <div class="grid gap-8 sm:grid-cols-3">
          <div>
            <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Product</p>
            <ul class="mt-4 space-y-3">
              <li v-for="link in productLinks" :key="link.href">
                <a :href="link.href" class="text-paper/70 transition-colors hover:text-paper">{{ link.label }}</a>
              </li>
            </ul>
          </div>

          <div>
            <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Account</p>
            <ul class="mt-4 space-y-3">
              <li><RouterLink to="/register" class="text-paper/70 transition-colors hover:text-paper">Register a salon</RouterLink></li>
              <li><RouterLink to="/login" class="text-paper/70 transition-colors hover:text-paper">Salon log in</RouterLink></li>
              <li><RouterLink to="/salons" class="text-paper/70 transition-colors hover:text-paper">Find a salon</RouterLink></li>
              <li><RouterLink to="/account/login" class="text-paper/70 transition-colors hover:text-paper">My bookings</RouterLink></li>
            </ul>
          </div>

          <div>
            <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Legal</p>
            <ul class="mt-4 space-y-3">
              <li><RouterLink to="/terms" class="text-paper/70 transition-colors hover:text-paper">Terms of Service</RouterLink></li>
              <li><RouterLink to="/privacy" class="text-paper/70 transition-colors hover:text-paper">Privacy Policy</RouterLink></li>
              <li><RouterLink to="/refund" class="text-paper/70 transition-colors hover:text-paper">Refund Policy</RouterLink></li>
            </ul>
          </div>
        </div>
      </div>

      <p class="mt-12 border-t border-paper/10 pt-8 text-sm text-paper/50">© 2026 Glowhub. All rights reserved.</p>
    </div>
  </footer>
</template>
```

- [ ] **Step 5: Drop ContactSection from LandingView.vue and delete it**

Remove its import and its element, then:

```bash
git rm src/components/marketing/ContactSection.vue
```

- [ ] **Step 6: Run the whole suite**

Run: `npm run test:unit`
Expected: PASS — including `SalonSearchView.spec.js`, which mounts `MarketingNav`. If it fails on a changed label, the assertion belongs to the nav's contract; update it there and say so in the commit.

- [ ] **Step 7: Commit**

```bash
git add src/components/marketing/MarketingNav.vue src/components/marketing/MarketingNav.spec.js \
        src/components/marketing/MarketingFooter.vue src/components/marketing/MarketingFooter.spec.js \
        src/components/marketing/HowItWorksSection.vue src/views/LandingView.vue
git commit -m "feat(marketing): rebrand the chrome and fold contact into the footer

The nav and footer are the two pieces shared with /salons and the legal
pages, so this is where the Glowhub name has to land. The Contact anchor
goes with the section it pointed at: a full-height form sat between the
FAQ and the closing ask, in front of readers who were not writing to us.
It is a short form at the bottom now."
```

---

### Task 9: Compose the page and prove the whole thing

**Files:**
- Modify: `src/views/LandingView.vue`
- Test: `src/views/LandingView.spec.js`

**Interfaces:**
- Consumes: every section component from Tasks 2–8.
- Produces: the final section order — `MarketingNav`, `HeroSection`, `PainSection`, `ProductTourSection`, `HowItWorksSection`, `PricingSection`, `TrustSection`, `FaqSection`, `CtaSection`, `MarketingFooter`, `StickyMobileCta`.

- [ ] **Step 1: Write the failing test**

Create `src/views/LandingView.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))
vi.mock('@/lib/api', () => ({ default: { post: vi.fn() } }))

import LandingView from './LandingView.vue'

const EXPECTED_ORDER = [
  'MarketingNav',
  'HeroSection',
  'PainSection',
  'ProductTourSection',
  'HowItWorksSection',
  'PricingSection',
  'TrustSection',
  'FaqSection',
  'CtaSection',
  'MarketingFooter',
  'StickyMobileCta',
]

describe('LandingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    // The sticky bar observes the hero; jsdom has no IntersectionObserver.
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        observe() {}
        disconnect() {}
      },
    )
  })

  it('argues in the intended order', () => {
    const wrapper = mount(LandingView)
    const rendered = EXPECTED_ORDER.filter((name) => wrapper.findComponent({ name }).exists())

    expect(rendered).toEqual(EXPECTED_ORDER)
  })

  it('never calls the product by its old name', () => {
    const wrapper = mount(LandingView)

    expect(wrapper.text()).not.toContain('SalonHub')
  })

  it('quotes no price in dollars', () => {
    const wrapper = mount(LandingView)

    expect(wrapper.text()).not.toContain('$')
  })

  it('points every filled call to action at registration', () => {
    const wrapper = mount(LandingView)
    const filled = wrapper.findAll('a').filter((a) => a.classes().includes('bg-brand-500'))

    expect(filled.length).toBeGreaterThan(2)
    expect(filled.every((a) => a.attributes('href') === '/register')).toBe(true)
  })

  it('keeps every anchor in the nav pointing at a section that exists', () => {
    const wrapper = mount(LandingView)
    const anchors = wrapper
      .findAll('a')
      .map((a) => a.attributes('href'))
      .filter((href) => href?.startsWith('#') && href !== '#top')

    for (const href of new Set(anchors)) {
      expect(wrapper.find(`section${href}`).exists()).toBe(true)
    }
  })
})
```

`findComponent({ name })` resolves against each SFC's inferred name, which comes from its filename — no `defineOptions` is needed.

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- src/views/LandingView`
Expected: FAIL on the ordering assertion until `LandingView.vue` matches the list.

- [ ] **Step 3: Write the final LandingView.vue**

```vue
<script setup>
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import HeroSection from '@/components/marketing/HeroSection.vue'
import PainSection from '@/components/marketing/PainSection.vue'
import ProductTourSection from '@/components/marketing/ProductTourSection.vue'
import HowItWorksSection from '@/components/marketing/HowItWorksSection.vue'
import PricingSection from '@/components/marketing/PricingSection.vue'
import TrustSection from '@/components/marketing/TrustSection.vue'
import FaqSection from '@/components/marketing/FaqSection.vue'
import CtaSection from '@/components/marketing/CtaSection.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'
import StickyMobileCta from '@/components/marketing/StickyMobileCta.vue'
</script>

<template>
  <div class="min-h-screen bg-paper text-ink">
    <MarketingNav />
    <main>
      <HeroSection />
      <PainSection />
      <ProductTourSection />
      <HowItWorksSection />
      <PricingSection />
      <TrustSection />
      <FaqSection />
      <CtaSection />
    </main>
    <MarketingFooter />
    <StickyMobileCta />
  </div>
</template>
```

- [ ] **Step 4: Run the whole suite**

Run: `npm run test:unit`
Expected: PASS, every file.

- [ ] **Step 5: Check it in a browser at 360px**

Run: `npm run dev`, open `/`, and set the device width to 360px. Confirm, in order:

- the page never scrolls sideways
- the h1 wraps to at most four lines
- the sticky bar appears after the hero and disappears at desktop width
- every tour block reads text first, then its mock
- the footer form's inputs are legible on the dark ground

Note anything wrong and fix it before committing; do not commit a page you have not looked at.

- [ ] **Step 6: Commit**

```bash
git add src/views/LandingView.vue src/views/LandingView.spec.js
git commit -m "feat(marketing): compose the redesigned landing page

Ten sections in the order the argument needs: the problem, the product,
the setup, the price, what being new means, the objections, the ask. The
spec covering the page asserts the order, the absence of the old brand
name and of dollar prices, that every filled button goes to /register,
and that no nav anchor points at a section that no longer exists."
```

---

## Self-Review

**Spec coverage.** Every section of the design maps to a task: nav and footer → 8; hero → 2; pain → 3; tour → 4; how-it-works and pricing → 5; trust and FAQ → 6; final CTA and sticky bar → 7; composition, ordering and the page-level assertions → 9. Primitives and mocks are covered by 1, 2 and 4. Removals: `FeaturesSection` (4), `TestimonialsSection` (6), `ContactSection` (8), stat band (never rebuilt — it lived in the artifact hero, not in the code hero). Mobile rules are distributed to the task that owns each surface, with the 360px check in 9.

**Two corrections to the spec, made here deliberately:**

1. The spec wrote the booking address as `glowhub.com/your-salon`. The product actually issues `{slug}.{APP_DOMAIN}` — see `SubdomainBanner.vue`. Task 4 renders `your-salon.{APP_DOMAIN}` instead, which is what an owner is really given.
2. The spec put the hero's floating cards behind a phone/desktop branch. Task 2 removes them outright and moves their one fact inside the card — one branch fewer to keep in sync, and the same information survives. The dual-branch test the spec asked for is therefore not needed; `LandingView.spec.js` covers the sticky bar instead.

**Placeholders:** none. Every code step carries the code.

**Type consistency:** `SectionHeading` (`eyebrow`/`title`/`lede`/`align`), `RuleList` (`items[].term`/`.text`/`.strong`) and `MarketingCta` (`to`/`label`/`variant`/`block`) are used with those exact names in Tasks 2–8. Section ids `#top`, `#features`, `#how-it-works`, `#pricing`, `#faq` are each defined in one task and referenced by the nav and footer in Task 8, and Task 9's last test fails if any of them is missing.
