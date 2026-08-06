<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { publicApiBase } from '@/lib/tenantHost'

const route = useRoute()

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

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

const branch = computed(() => site.value?.branches?.[0] || null)

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

// Google's embed needs no API key when handed a query. Coordinates win
// when the salon has them; the street address is the fallback.
const mapSrc = computed(() => {
  const b = branch.value
  if (!b) return ''
  const query = b.latitude != null && b.longitude != null
    ? `${b.latitude},${b.longitude}`
    : [b.address, b.city, b.country].filter(Boolean).join(', ')
  return query ? `https://www.google.com/maps?q=${encodeURIComponent(query)}&z=15&output=embed` : ''
})

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
  if (event.key === 'Escape') lightbox.value = null
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  load()
})

onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="min-h-screen bg-stone-50 text-stone-800" :style="{ '--accent': site?.theme_color || '#6366f1' }">
    <div v-if="loading" class="mx-auto max-w-5xl px-6 py-24">
      <div class="h-72 animate-pulse rounded-3xl bg-stone-200" />
      <div class="mt-8 h-6 w-1/3 animate-pulse rounded bg-stone-200" />
      <div class="mt-3 h-4 w-2/3 animate-pulse rounded bg-stone-200" />
    </div>

    <div v-else-if="loadError" class="mx-auto max-w-md px-6 py-32 text-center">
      <p class="font-serif text-2xl text-stone-900">Nothing here</p>
      <p class="mt-2 text-sm text-stone-500">{{ loadError }}</p>
    </div>

    <template v-else-if="site">
      <!-- Hero -->
      <header class="relative isolate overflow-hidden">
        <img
          v-if="site.cover_image_url"
          :src="site.cover_image_url"
          alt=""
          class="absolute inset-0 -z-10 h-full w-full object-cover"
        />
        <div
          class="absolute inset-0 -z-10"
          :class="site.cover_image_url ? 'bg-stone-900/60' : ''"
          :style="!site.cover_image_url ? { background: 'var(--accent)' } : {}"
        />

        <div class="mx-auto max-w-5xl px-6 py-24 sm:py-32">
          <img
            v-if="site.logo_url"
            :src="site.logo_url"
            :alt="site.name"
            class="mb-6 h-20 w-20 rounded-2xl object-cover ring-2 ring-white/70"
          />
          <h1 class="font-serif text-4xl leading-tight text-white sm:text-6xl">{{ site.name }}</h1>
          <p v-if="site.about" class="mt-4 max-w-xl text-base text-white/80">
            {{ site.about }}
          </p>

          <a v-if="rating" href="#reviews" class="mt-5 inline-flex items-center gap-2">
            <span class="flex text-amber-400">
              <svg
                v-for="star in 5"
                :key="star"
                class="h-5 w-5"
                :fill="star <= Math.round(rating.average) ? 'currentColor' : 'none'"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
              </svg>
            </span>
            <span class="text-sm font-medium text-white">{{ rating.average }} · {{ rating.count }} review{{ rating.count === 1 ? '' : 's' }}</span>
          </a>

          <div class="mt-8 flex flex-wrap items-center gap-3">
            <RouterLink
              :to="`/book/${site.slug}`"
              class="rounded-full bg-white px-6 py-3 text-sm font-semibold text-stone-900 shadow-sm transition hover:bg-stone-100"
            >
              Book an appointment
            </RouterLink>
            <a
              v-if="site.phone"
              :href="`tel:${site.phone}`"
              class="rounded-full border border-white/60 px-6 py-3 text-sm font-medium text-white transition hover:bg-white/10"
            >
              {{ site.phone }}
            </a>
            <RouterLink
              to="/account/login"
              class="text-sm font-medium text-white/80 underline-offset-4 transition hover:text-white hover:underline"
            >
              Manage my bookings
            </RouterLink>
          </div>
        </div>
      </header>

      <!-- Section nav -->
      <nav v-if="sections.length" class="sticky top-0 z-20 border-b border-stone-200 bg-stone-50/90 backdrop-blur">
        <div class="mx-auto flex max-w-5xl gap-6 overflow-x-auto px-6 py-3 text-sm">
          <a
            v-for="section in sections"
            :key="section.id"
            :href="`#${section.id}`"
            class="whitespace-nowrap text-stone-500 transition hover:text-stone-900"
          >
            {{ section.label }}
          </a>
          <RouterLink
            :to="`/book/${site.slug}`"
            class="ml-auto whitespace-nowrap font-semibold text-[var(--accent)]"
          >
            Book
          </RouterLink>
        </div>
      </nav>

      <main class="mx-auto max-w-5xl space-y-24 px-6 py-20">
        <!-- About -->
        <section v-if="site.about" id="about" class="grid gap-10 sm:grid-cols-[2fr_1fr] sm:items-center">
          <div>
            <p class="text-xs font-semibold tracking-[0.2em] text-[var(--accent)] uppercase">About</p>
            <h2 class="mt-3 font-serif text-3xl text-stone-900">Our story</h2>
            <p class="mt-4 text-base leading-relaxed whitespace-pre-line text-stone-600">{{ site.about }}</p>
          </div>
          <img
            v-if="site.gallery?.length"
            :src="site.gallery[0].image_url"
            :alt="site.gallery[0].title || ''"
            class="aspect-3/4 w-full rounded-3xl object-cover shadow-sm"
          />
        </section>

        <!-- Services -->
        <section v-if="services.length" id="services">
          <p class="text-xs font-semibold tracking-[0.2em] text-[var(--accent)] uppercase">Services</p>
          <h2 class="mt-3 font-serif text-3xl text-stone-900">What we do</h2>

          <ul class="mt-8 divide-y divide-stone-200 border-y border-stone-200">
            <li
              v-for="service in services"
              :key="service.id"
              class="flex flex-wrap items-baseline justify-between gap-2 py-4"
            >
              <div class="min-w-0">
                <p class="font-medium text-stone-900">{{ service.name }}</p>
                <p v-if="service.description" class="mt-0.5 text-sm text-stone-500">{{ service.description }}</p>
              </div>
              <div class="text-right text-sm">
                <p class="font-semibold text-stone-900">{{ money(service.price) }}</p>
                <p class="text-stone-500">{{ service.duration }} min</p>
              </div>
            </li>
          </ul>

          <RouterLink
            :to="`/book/${site.slug}`"
            class="mt-8 inline-block rounded-full px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
            :style="{ background: 'var(--accent)' }"
          >
            Book a service
          </RouterLink>
        </section>

        <!-- Team -->
        <section v-if="site.team?.length" id="team">
          <p class="text-xs font-semibold tracking-[0.2em] text-[var(--accent)] uppercase">Team</p>
          <h2 class="mt-3 font-serif text-3xl text-stone-900">The people behind the chair</h2>

          <div class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="member in site.team" :key="member.id" class="text-center">
              <img
                v-if="member.photo_url"
                :src="member.photo_url"
                :alt="member.name"
                class="mx-auto h-32 w-32 rounded-full object-cover"
              />
              <div
                v-else
                class="mx-auto flex h-32 w-32 items-center justify-center rounded-full font-serif text-2xl text-white"
                :style="{ background: 'var(--accent)' }"
              >
                {{ initials(member.name) }}
              </div>
              <p class="mt-4 font-medium text-stone-900">{{ member.name }}</p>
              <p v-if="member.designation" class="text-sm text-stone-500">{{ member.designation }}</p>
              <div
                v-if="member.rating && member.rating.average !== null"
                class="mt-1.5 flex items-center justify-center gap-1 text-sm text-stone-500"
              >
                <svg class="h-4 w-4 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                </svg>
                {{ member.rating.average }}
                <span class="text-stone-400">({{ member.rating.count }})</span>
              </div>
              <p v-if="member.bio" class="mt-2 text-sm leading-relaxed text-stone-600">{{ member.bio }}</p>
            </article>
          </div>
        </section>

        <!-- Reviews -->
        <section v-if="site.reviews?.length" id="reviews">
          <p class="text-xs font-semibold tracking-[0.2em] text-[var(--accent)] uppercase">Reviews</p>
          <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
            <h2 class="font-serif text-3xl text-stone-900">What guests say</h2>
            <div v-if="rating" class="flex items-center gap-2">
              <span class="flex text-amber-400">
                <svg
                  v-for="star in 5"
                  :key="star"
                  class="h-5 w-5"
                  :fill="star <= Math.round(rating.average) ? 'currentColor' : 'none'"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                </svg>
              </span>
              <span class="text-sm font-medium text-stone-700">{{ rating.average }} · {{ rating.count }} review{{ rating.count === 1 ? '' : 's' }}</span>
            </div>
          </div>

          <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <figure
              v-for="review in site.reviews"
              :key="review.id"
              class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-stone-200"
            >
              <div class="flex text-amber-400">
                <svg
                  v-for="star in 5"
                  :key="star"
                  class="h-4 w-4"
                  :fill="star <= review.rating ? 'currentColor' : 'none'"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                </svg>
              </div>
              <blockquote v-if="review.comment" class="mt-3 text-sm leading-relaxed text-stone-600">
                “{{ review.comment }}”
              </blockquote>
              <figcaption class="mt-4 text-sm">
                <span class="font-medium text-stone-900">{{ review.name }}</span>
                <span v-if="review.created_at" class="text-stone-400"> · {{ reviewDate(review.created_at) }}</span>
              </figcaption>
            </figure>
          </div>
        </section>

        <!-- Gallery -->
        <section v-if="site.gallery?.length" id="gallery">
          <p class="text-xs font-semibold tracking-[0.2em] text-[var(--accent)] uppercase">Gallery</p>
          <h2 class="mt-3 font-serif text-3xl text-stone-900">Our work</h2>

          <div class="mt-8 columns-2 gap-4 sm:columns-3">
            <button
              v-for="(image, index) in site.gallery"
              :key="image.id"
              type="button"
              class="mb-4 block w-full overflow-hidden rounded-2xl"
              @click="lightbox = index"
            >
              <img
                :src="image.image_url"
                :alt="image.title || 'Salon work'"
                class="w-full transition duration-300 hover:scale-[1.03]"
                loading="lazy"
              />
            </button>
          </div>
        </section>

        <!-- Visit -->
        <section v-if="branch" id="visit" class="grid gap-10 sm:grid-cols-2">
          <div>
            <p class="text-xs font-semibold tracking-[0.2em] text-[var(--accent)] uppercase">Visit</p>
            <h2 class="mt-3 font-serif text-3xl text-stone-900">Find us</h2>

            <address class="mt-4 space-y-1 text-base not-italic text-stone-600">
              <p v-if="branch.address">{{ branch.address }}</p>
              <p v-if="branch.city">{{ branch.city }}<span v-if="branch.country">, {{ branch.country }}</span></p>
              <p v-if="branch.phone || site.phone">
                <a :href="`tel:${branch.phone || site.phone}`" class="hover:text-stone-900">
                  {{ branch.phone || site.phone }}
                </a>
              </p>
              <p v-if="branch.email || site.email">
                <a :href="`mailto:${branch.email || site.email}`" class="hover:text-stone-900">
                  {{ branch.email || site.email }}
                </a>
              </p>
            </address>

            <dl v-if="branch.hours?.length" class="mt-6 space-y-1 text-sm">
              <div v-for="hour in branch.hours" :key="hour.weekday" class="flex justify-between border-b border-stone-200 py-1.5">
                <dt class="text-stone-500">{{ DAYS[hour.weekday] }}</dt>
                <dd :class="hour.is_closed ? 'text-stone-400' : 'font-medium text-stone-800'">
                  {{ hourLabel(hour) }}
                </dd>
              </div>
            </dl>

            <div v-if="socialLinks.length" class="mt-6 flex flex-wrap gap-3 text-sm">
              <a
                v-for="link in socialLinks"
                :key="link.name"
                :href="link.url"
                target="_blank"
                rel="noopener"
                class="rounded-full border border-stone-300 px-4 py-1.5 capitalize transition hover:border-stone-500"
              >
                {{ link.name }}
              </a>
            </div>
          </div>

          <iframe
            v-if="mapSrc"
            :src="mapSrc"
            class="h-80 w-full rounded-3xl border-0 shadow-sm sm:h-full"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            :title="`Map to ${site.name}`"
          />
        </section>
      </main>

      <footer class="border-t border-stone-200 py-10 text-center text-sm text-stone-500">
        <p>{{ site.name }}</p>
        <RouterLink :to="`/book/${site.slug}`" class="mt-2 inline-block font-medium text-[var(--accent)]">
          Book an appointment
        </RouterLink>
      </footer>

      <!-- Lightbox -->
      <div
        v-if="lightbox !== null"
        class="fixed inset-0 z-50 flex items-center justify-center bg-stone-950/90 p-6"
        @click="lightbox = null"
      >
        <figure class="max-h-full max-w-4xl">
          <img
            :src="site.gallery[lightbox].image_url"
            :alt="site.gallery[lightbox].title || ''"
            class="max-h-[80vh] w-full rounded-2xl object-contain"
          />
          <figcaption v-if="site.gallery[lightbox].title" class="mt-3 text-center text-sm text-white/70">
            {{ site.gallery[lightbox].title }}
          </figcaption>
        </figure>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* The dashboard is a tool; this page is a shopfront — a serif display
   face does more for it than another sans-serif heading. */
.font-serif {
  font-family: ui-serif, 'Iowan Old Style', 'Palatino Linotype', Georgia, serif;
}
</style>
