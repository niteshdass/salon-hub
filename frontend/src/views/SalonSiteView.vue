<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useSessionLink } from '@/lib/sessionLink'
import { publicApiBase } from '@/lib/tenantHost'

const route = useRoute()

// A visitor who is already signed in — as a customer or as salon staff — gets
// the way back into their own area alongside the salon's own links.
const session = useSessionLink()

// Two ways in: `/salon/:slug` on any host, and `/` on the salon's own
// subdomain. In the second case there is no slug in the path, so the calls
// below drop the segment and let the server read the tenant from the Host
// header rather than from a slug this page guessed. Every "Book" link uses
// site.slug, which comes back from that same resolved tenant.
const apiBase = publicApiBase(route.params.slug)

const site = ref(null)
const services = ref([])
const loading = ref(true)
const loadError = ref('')

// Index of the gallery photo shown full-size, or null for none.
const lightbox = ref(null)

// Drives the top bar's shift from transparent (over the hero) to solid.
const scrolled = ref(false)
const menuOpen = ref(false)

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

// One five-pointed star, shared by the three places that draw a rating row.
const STAR = 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z'

const branch = computed(() => site.value?.branches?.[0] || null)

// The hero wants a photograph of the room. Most salons never upload a cover
// image but do fill their gallery, so its first photo stands in; only when
// there is neither does the hero fall back to the painted gradient.
const heroImage = computed(
  () => site.value?.cover_image_url || site.value?.gallery?.[0]?.image_url || null,
)

// The decorative mirrors are drawn from the gallery too. Skip the photo the
// hero just borrowed — the same picture behind and beside itself reads as a
// mistake.
const mirrors = computed(() => {
  const gallery = site.value?.gallery || []

  return (site.value?.cover_image_url ? gallery : gallery.slice(1)).slice(0, 2)
})

// The page is one dark room lit by a single warm accent, so an untouched
// salon must not paint it with the API's indigo placeholder. A salon that
// has actually chosen a colour keeps it; everyone else gets the brass.
const accent = computed(() => {
  const chosen = site.value?.theme_color
  return !chosen || chosen.toLowerCase() === '#6366f1' ? '#c8a45d' : chosen
})

const sections = computed(() => [
  { id: 'about', label: 'About', show: !!site.value?.about },
  { id: 'services', label: 'Services', show: services.value.length > 0 },
  { id: 'team', label: 'Team', show: (site.value?.team?.length || 0) > 0 },
  { id: 'reviews', label: 'Reviews', show: (site.value?.reviews?.length || 0) > 0 },
  { id: 'gallery', label: 'Gallery', show: (site.value?.gallery?.length || 0) > 0 },
  { id: 'visit', label: 'Visit', show: !!branch.value },
].filter((s) => s.show))

// Salon-wide rating, present only once at least one review is published.
const rating = computed(() => {
  const r = site.value?.rating
  return r && r.average !== null ? r : null
})

// The name is set in two weights — first word bright, the rest muted — the
// way the reference splits a salon's name across two lines.
const nameParts = computed(() => {
  const words = (site.value?.name || '').trim().split(/\s+/)
  if (words.length < 2) return { lead: words[0] || '', tail: '' }
  return { lead: words[0], tail: words.slice(1).join(' ') }
})

// Google's embed needs no API key when handed a query. Coordinates win
// when the salon has them; the street address is the fallback.
const mapQuery = computed(() => {
  const b = branch.value
  if (!b) return ''
  return b.latitude != null && b.longitude != null
    ? `${b.latitude},${b.longitude}`
    : [b.address, b.city, b.country].filter(Boolean).join(', ')
})

const mapSrc = computed(() =>
  mapQuery.value ? `https://www.google.com/maps?q=${encodeURIComponent(mapQuery.value)}&z=15&output=embed` : '',
)

const mapLink = computed(() =>
  mapQuery.value ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(mapQuery.value)}` : '',
)

const socialLinks = computed(() =>
  Object.entries(site.value?.social || {})
    .filter(([, url]) => !!url)
    .map(([name, url]) => ({ name, url })),
)

function money(value) {
  if (value === null || value === undefined || value === '') return ''
  const amount = Number(value)
  if (Number.isNaN(amount)) return String(value)
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: site.value?.currency || 'USD',
      maximumFractionDigits: 2,
    }).format(amount)
  } catch {
    return amount.toFixed(2)
  }
}

function hourLabel(hour) {
  if (hour.is_closed || !hour.open_time) return 'Closed'
  return `${hour.open_time} – ${hour.close_time}`
}

function reviewDate(value) {
  if (!value) return ''
  return new Date(value).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
}

function initials(name) {
  return (name || '')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
}

// 1 -> "01": the services list is numbered like a printed price card.
function ordinal(index) {
  return String(index + 1).padStart(2, '0')
}

function closeMenu() {
  menuOpen.value = false
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    // The page needs both to render; a failed services call should not
    // cost the visitor the rest of the site.
    const [siteResponse, servicesResponse] = await Promise.all([
      api.get(`${apiBase}/site`),
      api.get(`${apiBase}/services`).catch(() => ({ data: { data: [] } })),
    ])
    site.value = siteResponse.data.data
    services.value = servicesResponse.data.data || []
    document.title = site.value?.name ? `${site.value.name}` : document.title
  } catch (err) {
    loadError.value = parseApiError(err, 'This salon page is not available.').message
  } finally {
    loading.value = false
  }
}

function onKey(event) {
  if (event.key !== 'Escape') return
  lightbox.value = null
  menuOpen.value = false
}

function onScroll() {
  scrolled.value = window.scrollY > 24
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
  load()
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', onScroll)
})
</script>

<template>
  <div class="salon-site" :style="{ '--accent': accent }">
    <div v-if="loading" class="mx-auto max-w-6xl px-6 py-40">
      <div class="h-4 w-24 animate-pulse rounded bg-white/10" />
      <div class="mt-8 h-24 w-2/3 animate-pulse rounded bg-white/10" />
      <div class="mt-4 h-24 w-1/2 animate-pulse rounded bg-white/5" />
      <div class="mt-12 h-12 w-56 animate-pulse rounded-sm bg-white/10" />
    </div>

    <div v-else-if="loadError" class="mx-auto max-w-md px-6 py-40 text-center">
      <p class="font-display text-4xl text-white">Nothing here</p>
      <p class="mt-3 text-sm text-white/45">{{ loadError }}</p>
    </div>

    <template v-else-if="site">
      <!-- Top bar: transparent over the hero, solid once the visitor scrolls -->
      <header
        class="fixed inset-x-0 top-0 z-40 transition-colors duration-500"
        :class="scrolled || menuOpen ? 'border-b border-white/8 bg-[#0a0908]/95 backdrop-blur-md' : 'bg-transparent'"
      >
        <div class="mx-auto flex max-w-7xl items-center gap-6 px-6 py-5 lg:px-10">
          <a href="#top" class="flex shrink-0 items-center gap-2.5" @click="closeMenu">
            <img v-if="site.logo_url" :src="site.logo_url" :alt="site.name" class="h-7 w-7 object-contain" />
            <span v-else class="text-[var(--accent)]">✦</span>
            <span class="label text-white">{{ site.name }}</span>
          </a>

          <nav v-if="sections.length" class="ml-auto hidden items-center gap-9 lg:flex">
            <a
              v-for="section in sections"
              :key="section.id"
              :href="`#${section.id}`"
              class="label nav-link text-white/55"
            >
              {{ section.label }}
            </a>
          </nav>

          <div class="ml-auto flex items-center gap-5 lg:ml-8">
            <a v-if="site.phone" :href="`tel:${site.phone}`" class="label hidden text-white/55 transition hover:text-white sm:block">
              {{ site.phone }}
            </a>
            <RouterLink
              v-if="session"
              :to="session.to"
              class="label nav-link hidden text-white/55 sm:block"
            >
              {{ session.label }}
            </RouterLink>
            <RouterLink :to="`/book/${site.slug}`" class="btn-gold">Book</RouterLink>
            <button
              v-if="sections.length || session"
              type="button"
              class="label text-white/70 lg:hidden"
              :aria-expanded="menuOpen"
              aria-label="Menu"
              @click="menuOpen = !menuOpen"
            >
              {{ menuOpen ? 'Close' : 'Menu' }}
            </button>
          </div>
        </div>

        <nav v-if="menuOpen" class="border-t border-white/8 px-6 py-4 lg:hidden">
          <a
            v-for="section in sections"
            :key="section.id"
            :href="`#${section.id}`"
            class="label block py-2.5 text-white/60"
            @click="closeMenu"
          >
            {{ section.label }}
          </a>
          <RouterLink
            v-if="session"
            :to="session.to"
            class="label block py-2.5 text-[var(--accent)]"
            @click="closeMenu"
          >
            {{ session.label }}
          </RouterLink>
        </nav>
      </header>

      <!-- Hero -->
      <section id="top" class="relative isolate min-h-[92vh] overflow-hidden">
        <img
          v-if="heroImage"
          :src="heroImage"
          alt=""
          class="absolute inset-0 -z-20 h-full w-full scale-105 object-cover opacity-70"
        />
        <div v-else class="absolute inset-0 -z-20 bg-[radial-gradient(120%_90%_at_75%_20%,#2a241d_0%,#0a0908_65%)]" />
        <div class="absolute inset-0 -z-10 bg-[linear-gradient(100deg,#080706_18%,rgba(8,7,6,0.72)_52%,rgba(8,7,6,0.28)_100%)]" />
        <!-- A single cold strip of light across the frame, as in a salon mirror -->
        <div class="pointer-events-none absolute top-[14%] right-[6%] -z-10 hidden h-px w-[26%] bg-white/45 blur-[2px] lg:block" />

        <!-- Decorative mirrors: gallery photos, dropped silently when absent -->
        <template v-if="mirrors.length">
          <div v-if="mirrors[1]" class="mirror mirror-sm" aria-hidden="true">
            <img :src="mirrors[1].image_url" alt="" class="h-full w-full object-cover opacity-50" />
          </div>
        </template>

        <div class="mx-auto flex min-h-[92vh] max-w-7xl flex-col justify-end px-6 pt-40 pb-20 lg:px-10 lg:pb-28">
          <p class="rule-label reveal text-[var(--accent)]" style="--d: 0ms">
            Premium salon<template v-if="branch?.city"> — {{ branch.city }}</template>
          </p>

          <h1 class="reveal mt-7 font-display leading-[0.88] tracking-[-0.02em]" style="--d: 90ms">
            <span class="block text-[clamp(3.2rem,10vw,7.5rem)] text-white">{{ nameParts.lead }}</span>
            <span v-if="nameParts.tail" class="block text-[clamp(3.2rem,10vw,7.5rem)] text-white/35">{{ nameParts.tail }}</span>
          </h1>

          <p v-if="site.about" class="reveal mt-7 max-w-lg text-[0.95rem] leading-relaxed text-white/55" style="--d: 170ms">
            {{ site.about }}
          </p>

          <a v-if="rating" href="#reviews" class="reveal mt-7 inline-flex w-fit items-center gap-2.5" style="--d: 210ms">
            <span class="flex text-[var(--accent)]">
              <svg
                v-for="star in 5"
                :key="star"
                class="h-4 w-4"
                :fill="star <= Math.round(rating.average) ? 'currentColor' : 'none'"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
              >
                <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
              </svg>
            </span>
            <span class="label text-white/70">
              {{ rating.average }} · {{ rating.count }} review{{ rating.count === 1 ? '' : 's' }}
            </span>
          </a>

          <div class="reveal mt-11 flex flex-wrap items-center gap-3" style="--d: 260ms">
            <RouterLink :to="`/book/${site.slug}`" class="btn-gold btn-lg">Book an appointment</RouterLink>
            <a v-if="site.phone" :href="`tel:${site.phone}`" class="btn-ghost btn-lg">{{ site.phone }}</a>
            <RouterLink
              :to="session ? session.to : '/account/login'"
              class="label ml-1 text-white/45 transition hover:text-white"
            >
              {{ session ? session.label : 'Manage bookings' }}
            </RouterLink>
          </div>
        </div>
      </section>

      <!-- About -->
      <section v-if="site.about" id="about" class="band band-deep">
        <div class="shell grid gap-14 lg:grid-cols-[1.15fr_0.85fr] lg:items-center">
          <div>
            <p class="rule-label text-[var(--accent)]">About</p>
            <h2 class="section-title mt-6">
              Our <span class="text-white/35">story</span>
            </h2>
            <p class="mt-7 max-w-xl text-[0.95rem] leading-[1.9] whitespace-pre-line text-white/55">{{ site.about }}</p>
          </div>
          <img
            v-if="site.gallery?.length"
            :src="site.gallery[0].image_url"
            :alt="site.gallery[0].title || ''"
            class="aspect-3/4 w-full object-cover grayscale-[35%]"
            loading="lazy"
          />
        </div>
      </section>

      <!-- Services -->
      <section v-if="services.length" id="services" class="band band-deep">
        <div class="shell">
          <p class="rule-label text-[var(--accent)]">Services</p>
          <div class="mt-6 flex flex-wrap items-end justify-between gap-6">
            <h2 class="section-title">
              What <span class="text-white/35">we do</span>
            </h2>
            <RouterLink :to="`/book/${site.slug}`" class="btn-ghost">Book a service →</RouterLink>
          </div>

          <ul class="mt-14 border-t border-white/8">
            <li
              v-for="(service, index) in services"
              :key="service.id"
              class="service-row group flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-white/8 px-2 py-5 transition-colors"
            >
              <span class="w-7 shrink-0 text-[0.65rem] tracking-[0.15em] text-white/25 tabular-nums">
                {{ ordinal(index) }}
              </span>
              <div class="min-w-0 flex-1">
                <p class="font-display text-lg text-white">{{ service.name }}</p>
                <p v-if="service.description" class="mt-1 text-sm text-white/40">{{ service.description }}</p>
              </div>
              <span class="label shrink-0 text-white/35">{{ service.duration }} min</span>
              <span class="w-24 shrink-0 text-right font-display text-lg text-[var(--accent)]">
                {{ money(service.price) }}
              </span>
            </li>
          </ul>
        </div>
      </section>

      <!-- Team -->
      <section v-if="site.team?.length" id="team" class="band band-raised">
        <div class="shell">
          <p class="rule-label text-[var(--accent)]">Team</p>
          <h2 class="section-title mt-6">
            The people<br /><span class="text-white/35">behind the chair</span>
          </h2>

          <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <article
              v-for="member in site.team"
              :key="member.id"
              class="group relative isolate aspect-4/5 overflow-hidden bg-[#0a0908]"
            >
              <img
                v-if="member.photo_url"
                :src="member.photo_url"
                :alt="member.name"
                class="absolute inset-0 h-full w-full object-cover grayscale transition duration-700 group-hover:scale-105 group-hover:grayscale-0"
                loading="lazy"
              />
              <div
                v-else
                class="absolute inset-0 flex items-center justify-center font-display text-6xl text-white/15"
              >
                {{ initials(member.name) }}
              </div>
              <div class="absolute inset-0 bg-[linear-gradient(to_top,rgba(8,7,6,0.92)_0%,rgba(8,7,6,0.1)_55%)]" />

              <div class="absolute inset-x-0 bottom-0 p-5">
                <p class="font-display text-lg text-white">{{ member.name }}</p>
                <p v-if="member.designation" class="label mt-1 text-white/45">{{ member.designation }}</p>
                <div
                  v-if="member.rating && member.rating.average !== null"
                  class="mt-2 flex items-center gap-1.5 text-xs text-white/50"
                >
                  <svg class="h-3.5 w-3.5 text-[var(--accent)]" fill="currentColor" viewBox="0 0 24 24">
                    <path :d="STAR" />
                  </svg>
                  {{ member.rating.average }} <span class="text-white/30">({{ member.rating.count }})</span>
                </div>
                <p v-if="member.bio" class="mt-2 line-clamp-3 text-sm leading-relaxed text-white/45">{{ member.bio }}</p>
              </div>
            </article>
          </div>
        </div>
      </section>

      <!-- Reviews -->
      <section v-if="site.reviews?.length" id="reviews" class="band band-deep">
        <div class="shell">
          <p class="rule-label text-[var(--accent)]">Reviews</p>
          <div class="mt-6 flex flex-wrap items-end justify-between gap-6">
            <h2 class="section-title">
              What <span class="text-white/35">guests say</span>
            </h2>
            <div v-if="rating" class="flex items-center gap-3">
              <span class="font-display text-4xl text-[var(--accent)]">{{ rating.average }}</span>
              <div>
                <span class="flex text-[var(--accent)]">
                  <svg
                    v-for="star in 5"
                    :key="star"
                    class="h-3.5 w-3.5"
                    :fill="star <= Math.round(rating.average) ? 'currentColor' : 'none'"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
                  </svg>
                </span>
                <p class="label mt-1.5 text-white/40">
                  {{ rating.count }} review{{ rating.count === 1 ? '' : 's' }}
                </p>
              </div>
            </div>
          </div>

          <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <figure
              v-for="review in site.reviews"
              :key="review.id"
              class="flex flex-col border border-white/8 bg-[#131110] p-7 transition-colors duration-300 hover:border-[var(--accent)]/40"
            >
              <div class="flex text-[var(--accent)]">
                <svg
                  v-for="star in 5"
                  :key="star"
                  class="h-3.5 w-3.5"
                  :fill="star <= review.rating ? 'currentColor' : 'none'"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" :d="STAR" />
                </svg>
              </div>
              <blockquote v-if="review.comment" class="mt-5 flex-1 font-display text-lg leading-relaxed text-white/80">
                “{{ review.comment }}”
              </blockquote>
              <figcaption class="mt-6 border-t border-white/8 pt-4">
                <span class="label text-white">{{ review.name }}</span>
                <span v-if="review.created_at" class="label ml-2 text-white/30">{{ reviewDate(review.created_at) }}</span>
              </figcaption>
            </figure>
          </div>
        </div>
      </section>

      <!-- Gallery -->
      <section v-if="site.gallery?.length" id="gallery" class="band band-raised">
        <div class="shell">
          <p class="rule-label text-[var(--accent)]">Gallery</p>
          <h2 class="section-title mt-6">
            Our <span class="text-white/35">work</span>
          </h2>

          <div class="mt-14 grid grid-cols-2 gap-4 sm:grid-cols-3">
            <button
              v-for="(image, index) in site.gallery"
              :key="image.id"
              type="button"
              class="group relative aspect-4/3 overflow-hidden bg-[#0a0908]"
              @click="lightbox = index"
            >
              <img
                :src="image.image_url"
                :alt="image.title || 'Salon work'"
                class="h-full w-full object-cover transition duration-700 group-hover:scale-105"
                loading="lazy"
              />
              <span class="absolute inset-0 bg-[#080706]/0 transition group-hover:bg-black/25" />
            </button>
          </div>
        </div>
      </section>

      <!-- Visit -->
      <section v-if="branch" id="visit" class="band band-deep">
        <div class="shell grid gap-14 lg:grid-cols-2 lg:items-start">
          <div>
            <p class="rule-label text-[var(--accent)]">Visit</p>
            <h2 class="section-title mt-6">
              Find <span class="text-white/35">us</span>
            </h2>

            <address class="mt-9 space-y-4 text-[0.95rem] not-italic text-white/60">
              <p v-if="branch.address || branch.city" class="flex gap-3">
                <span class="text-[var(--accent)]">◎</span>
                <span>
                  <template v-if="branch.address">{{ branch.address }}<br /></template>
                  <template v-if="branch.city">{{ branch.city }}<span v-if="branch.country">, {{ branch.country }}</span></template>
                </span>
              </p>
              <p v-if="branch.phone || site.phone" class="flex gap-3">
                <span class="text-[var(--accent)]">✆</span>
                <a :href="`tel:${branch.phone || site.phone}`" class="transition hover:text-white">
                  {{ branch.phone || site.phone }}
                </a>
              </p>
              <p v-if="branch.email || site.email" class="flex gap-3">
                <span class="text-[var(--accent)]">✉</span>
                <a :href="`mailto:${branch.email || site.email}`" class="transition hover:text-white">
                  {{ branch.email || site.email }}
                </a>
              </p>
            </address>

            <template v-if="branch.hours?.length">
              <p class="rule-label mt-12 text-white/35">Opening hours</p>
              <dl class="mt-5 text-sm">
                <div
                  v-for="hour in branch.hours"
                  :key="hour.weekday"
                  class="flex items-baseline justify-between gap-4 border-b border-white/8 py-2.5"
                >
                  <dt class="text-white/45">{{ DAYS[hour.weekday] }}</dt>
                  <dd :class="hour.is_closed ? 'text-white/25' : 'tabular-nums text-white/80'">
                    {{ hourLabel(hour) }}
                  </dd>
                </div>
              </dl>
            </template>

            <div v-if="socialLinks.length" class="mt-9 flex flex-wrap gap-2.5">
              <a
                v-for="link in socialLinks"
                :key="link.name"
                :href="link.url"
                target="_blank"
                rel="noopener"
                class="btn-ghost capitalize"
              >
                {{ link.name }}
              </a>
            </div>

            <RouterLink :to="`/book/${site.slug}`" class="btn-gold btn-lg mt-11">Book an appointment</RouterLink>
          </div>

          <div v-if="mapSrc" class="relative">
            <iframe
              :src="mapSrc"
              class="h-[26rem] w-full border border-white/8 grayscale-[60%] lg:h-[34rem]"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
              :title="`Map to ${site.name}`"
            />
            <a
              v-if="mapLink"
              :href="mapLink"
              target="_blank"
              rel="noopener"
              class="btn-ghost absolute bottom-5 left-1/2 -translate-x-1/2 bg-[#0a0908]/90 backdrop-blur"
            >
              Open in maps
            </a>
          </div>
        </div>
      </section>

      <footer class="border-t border-white/8 bg-[#080706]">
        <div class="shell flex flex-wrap items-center justify-between gap-5 !py-9">
          <span class="label flex items-center gap-2.5 text-white/70">
            <span class="text-[var(--accent)]">✦</span> {{ site.name }}
          </span>
          <span class="label text-white/25">© {{ new Date().getFullYear() }} {{ site.name }}. All rights reserved.</span>
          <RouterLink :to="`/book/${site.slug}`" class="label nav-link text-[var(--accent)]">
            Book an appointment →
          </RouterLink>
        </div>
      </footer>

      <!-- Lightbox -->
      <div
        v-if="lightbox !== null"
        class="fixed inset-0 z-50 flex items-center justify-center bg-[#050403]/95 p-6"
        @click="lightbox = null"
      >
        <figure class="max-h-full max-w-4xl">
          <img
            :src="site.gallery[lightbox].image_url"
            :alt="site.gallery[lightbox].title || ''"
            class="max-h-[80vh] w-full object-contain"
          />
          <figcaption v-if="site.gallery[lightbox].title" class="label mt-4 text-center text-white/40">
            {{ site.gallery[lightbox].title }}
          </figcaption>
        </figure>
      </div>
    </template>
  </div>
</template>

<style scoped>
/*
 * The microsite is the salon's shopfront, not the dashboard: a dark room,
 * one warm light. Everything here is scoped to this page — the platform's
 * paper/terracotta theme deliberately stops at the tenant's own domain.
 */
.salon-site {
  --ink: #080706;
  background: #080706;
  color: #fff;
  font-family: var(--font-body);
  min-height: 100vh;
  scroll-behavior: smooth;
}

.font-display {
  font-family: var(--font-display);
  font-weight: 400;
}

/* Small uppercase sans is the page's only supporting voice. */
.label {
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

/* Section eyebrow: a short brass rule, then the word. */
.rule-label {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  font-size: 0.68rem;
  font-weight: 500;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.rule-label::before {
  content: '';
  width: 1.75rem;
  height: 1px;
  background: currentColor;
  opacity: 0.7;
}

.section-title {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(2.1rem, 4.6vw, 3.4rem);
  line-height: 1.08;
  letter-spacing: -0.015em;
  color: #fff;
}

.band {
  padding-block: clamp(5rem, 11vw, 9rem);
}

.band-deep {
  background: #080706;
}

.band-raised {
  background: #131110;
}

.shell {
  margin-inline: auto;
  max-width: 80rem;
  padding-inline: 1.5rem;
}

@media (min-width: 1024px) {
  .shell {
    padding-inline: 2.5rem;
  }
}

.nav-link {
  position: relative;
  transition: color 0.3s ease;
}

.nav-link::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: -0.4rem;
  height: 1px;
  width: 0;
  background: var(--accent);
  transition: width 0.35s ease;
}

.nav-link:hover {
  color: #fff;
}

.nav-link:hover::after {
  width: 100%;
}

.btn-gold,
.btn-ghost {
  display: inline-block;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  padding: 0.75rem 1.4rem;
  transition:
    background-color 0.3s ease,
    color 0.3s ease,
    border-color 0.3s ease;
  white-space: nowrap;
}

.btn-gold {
  background: var(--accent);
  color: #0a0908;
}

.btn-gold:hover {
  background: #fff;
}

.btn-ghost {
  border: 1px solid rgb(255 255 255 / 0.22);
  color: rgb(255 255 255 / 0.8);
}

.btn-ghost:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.btn-lg {
  padding: 1.05rem 2rem;
}

.service-row:hover {
  background: rgb(255 255 255 / 0.03);
}

/* Hero mirrors — decorative circles echoing a salon's wall mirrors. */
.mirror {
  position: absolute;
  z-index: -10;
  border-radius: 9999px;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / 0.12);
  display: none;
}

/* Kept clear of the fixed top bar, and away from the headline's first line. */
.mirror-lg {
  top: 24%;
  left: 7%;
  width: 11rem;
  height: 11rem;
}

.mirror-sm {
  top: 17%;
  left: 27%;
  width: 6rem;
  height: 6rem;
}

@media (min-width: 1024px) {
  .mirror {
    display: block;
  }
}

/* One orchestrated entrance, staggered by --d, rather than scattered motion. */
.reveal {
  animation: rise 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: var(--d, 0ms);
}

@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(1.5rem);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .reveal {
    animation: none;
  }
}
</style>
