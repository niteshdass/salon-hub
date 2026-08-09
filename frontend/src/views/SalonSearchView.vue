<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'
import { searchSalons } from '@/lib/discovery'

const route = useRoute()
const router = useRouter()

// A fixed vocabulary, not a real cross-tenant taxonomy: each salon keeps its
// own free-text service_categories, so there is nothing to pull chip labels
// from. These match the example services in the product spec and are sent to
// the backend as a plain keyword — the same substring match the search box
// itself uses.
const SERVICE_CHIPS = ['Hair cut', 'Hair colour', 'Hair spa', 'Facial', 'Massage', 'Bridal', 'Beard trim']

const SORTS = [
  { value: 'recommended', label: 'Recommended' },
  { value: 'top_rated', label: 'Top rated' },
  { value: 'price_asc', label: 'Price: low to high' },
]
const SORT_VALUES = SORTS.map((s) => s.value)

function stringQuery(value) {
  return typeof value === 'string' ? value : ''
}

// The URL is the source of truth for what was searched/filtered, so a result
// page can be shared, reloaded and reached again with the back button.
const q = ref(stringQuery(route.query.q))
const city = ref(stringQuery(route.query.city))
const service = ref(stringQuery(route.query.service))
const sort = ref(SORT_VALUES.includes(route.query.sort) ? route.query.sort : 'recommended')

const salons = ref([])
const total = ref(0)
const page = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const failed = ref(false)

// Every city a listed salon can be found in — comes back on every response
// (see meta.facets.cities), regardless of the active filters, so the chip
// row doesn't shrink when a city is already selected. Kept across requests
// rather than cleared on each run, so a failed request doesn't blank it.
const cities = ref([])

const hasFilters = computed(() => q.value.trim() !== '' || city.value !== '' || service.value !== '')

// Set once a search/filter has actually been run, so the empty state can
// tell "nothing matched" from "nothing is listed yet".
const searched = ref(hasFilters.value)

let timer = null
// Only the newest search may write results: a slow request for "ha" must not
// overwrite the finished one for "hair".
let latest = 0

function buildParams(term, pageNumber) {
  const params = { q: term, page: pageNumber }
  if (city.value) params.city = city.value
  if (service.value) params.service = service.value
  if (sort.value !== 'recommended') params.sort = sort.value
  return params
}

async function run() {
  const term = q.value.trim()
  const attempt = ++latest

  loading.value = true
  failed.value = false
  searched.value = term !== '' || city.value !== '' || service.value !== ''
  page.value = 1
  // A fresh search always wins the race (see the `attempt !== latest` guards
  // below and in showMore()), so any "Show more" in flight is now stale and
  // its own `finally` will never run to clear this. Reset it unconditionally
  // here or the button is stuck reading "Loading…", disabled, forever.
  loadingMore.value = false

  try {
    const { data, meta } = await searchSalons(buildParams(term, 1))
    if (attempt !== latest) return
    salons.value = data
    total.value = meta.total
    if (meta.facets?.cities) cities.value = meta.facets.cities
  } catch {
    if (attempt !== latest) return
    failed.value = true
    salons.value = []
    total.value = 0
  } finally {
    if (attempt === latest) loading.value = false
  }
}

// Fetches the next page and appends it to what's already showing. Guarded
// by the same `attempt === latest` token as run(): a new search started
// while this is in flight must win, not have a late page-2 response
// tacked onto its results.
async function showMore() {
  const term = q.value.trim()
  const attempt = ++latest
  const nextPage = page.value + 1

  loadingMore.value = true

  try {
    const { data } = await searchSalons(buildParams(term, nextPage))
    if (attempt !== latest) return
    salons.value = [...salons.value, ...data]
    page.value = nextPage
  } catch {
    // Leave the existing results and control in place; the customer can
    // just try "Show more" again.
  } finally {
    if (attempt === latest) loadingMore.value = false
  }
}

function updateUrl(term) {
  const query = {}
  if (term) query.q = term
  if (city.value) query.city = city.value
  if (service.value) query.service = service.value
  if (sort.value !== 'recommended') query.sort = sort.value
  router.replace({ query })
}

function onInput() {
  clearTimeout(timer)
  timer = setTimeout(() => {
    const term = q.value.trim()
    updateUrl(term)
    run()
  }, 300)
}

// Chip and sort changes apply immediately — only free text is debounced.
function applyNow() {
  clearTimeout(timer)
  updateUrl(q.value.trim())
  run()
}

function selectCity(value) {
  city.value = city.value === value ? '' : value
  applyNow()
}

function selectService(value) {
  service.value = service.value === value ? '' : value
  applyNow()
}

function clear() {
  q.value = ''
  city.value = ''
  service.value = ''
  sort.value = 'recommended'
  clearTimeout(timer)
  router.replace({ query: {} })
  run()
}

function priceLabel(salon) {
  if (!salon.price_from) return null
  // Trim a trailing ".00" — a price list, not a ledger.
  const amount = Number(salon.price_from)
  const shown = Number.isInteger(amount) ? amount.toString() : amount.toFixed(2)
  return `from ${salon.currency} ${shown}`
}

onMounted(run)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div class="bg-paper text-ink min-h-screen">
    <MarketingNav />

    <main class="mx-auto max-w-6xl px-6 py-14 lg:px-8">
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Book an appointment</p>
      <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight sm:text-5xl">Find a salon, spa or parlour</h1>
      <p class="mt-3 max-w-xl text-ink/60">
        Search by name, city, or the thing you want done.
      </p>

      <div class="relative mt-8 max-w-xl">
        <input
          v-model="q"
          type="search"
          placeholder="Hair spa, Sylhet, Chastity Hyde…"
          aria-label="Search salons, spas and parlours"
          class="w-full rounded-full border border-brand-100 bg-white px-6 py-3.5 text-base text-ink shadow-sm transition-shadow placeholder:text-ink/35 focus-visible:border-brand-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/30"
          @input="onInput"
        />
      </div>

      <!-- Filters -->
      <div class="mt-6 flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors"
          :class="service === ''
            ? 'bg-ink text-white'
            : 'bg-white text-ink/65 ring-1 ring-inset ring-brand-100 hover:bg-brand-50 hover:text-ink'"
          @click="service = ''; applyNow()"
        >
          All services
        </button>
        <button
          v-for="chip in SERVICE_CHIPS"
          :key="chip"
          type="button"
          class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors"
          :class="service === chip
            ? 'bg-ink text-white'
            : 'bg-white text-ink/65 ring-1 ring-inset ring-brand-100 hover:bg-brand-50 hover:text-ink'"
          @click="selectService(chip)"
        >
          {{ chip }}
        </button>

        <span v-if="cities.length" class="mx-1 hidden h-5 w-px bg-brand-100 sm:block" aria-hidden="true"></span>

        <template v-if="cities.length">
          <button
            type="button"
            class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors"
            :class="city === ''
              ? 'bg-ink text-white'
              : 'bg-white text-ink/65 ring-1 ring-inset ring-brand-100 hover:bg-brand-50 hover:text-ink'"
            @click="city = ''; applyNow()"
          >
            All cities
          </button>
          <button
            v-for="c in cities"
            :key="c"
            type="button"
            class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors"
            :class="city === c
              ? 'bg-ink text-white'
              : 'bg-white text-ink/65 ring-1 ring-inset ring-brand-100 hover:bg-brand-50 hover:text-ink'"
            @click="selectCity(c)"
          >
            {{ c }}
          </button>
        </template>
      </div>

      <div aria-live="polite">
        <!-- Loading -->
        <div v-if="loading" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="n in 6" :key="n" class="animate-pulse rounded-2xl border border-brand-100 bg-white p-5">
            <div class="h-36 rounded-xl bg-brand-50"></div>
            <div class="mt-4 h-4 w-2/3 rounded bg-brand-50"></div>
            <div class="mt-2 h-3 w-1/3 rounded bg-brand-50"></div>
          </div>
        </div>

        <!-- Failed -->
        <p v-else-if="failed" class="mt-12 text-ink/60">
          Couldn't load results. Check your connection and try again.
        </p>

        <!-- Results -->
        <template v-else-if="salons.length">
          <div class="mt-8 flex items-center gap-3 text-sm text-ink/50">
            <span>{{ total }} {{ total === 1 ? 'result' : 'results' }}</span>
            <button
              v-if="hasFilters"
              type="button"
              class="underline underline-offset-2 transition-colors hover:text-ink"
              @click="clear"
            >
              Clear filters
            </button>
            <label class="ml-auto flex items-center gap-2 text-ink/60">
              Sort
              <select
                v-model="sort"
                class="rounded-full border border-brand-100 bg-white py-1.5 pl-3 pr-8 text-sm text-ink shadow-sm focus-visible:border-brand-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/30"
                @change="applyNow"
              >
                <option v-for="option in SORTS" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
          </div>

          <div class="mt-5 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <RouterLink
              v-for="salon in salons"
              :key="salon.slug"
              :to="`/salon/${salon.slug}`"
              class="group block overflow-hidden rounded-2xl border border-brand-100 bg-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
            >
              <div class="relative h-40 bg-brand-50">
                <img
                  v-if="salon.cover_image_url"
                  :src="salon.cover_image_url"
                  :alt="salon.name"
                  class="h-40 w-full object-cover transition-transform duration-500 group-hover:scale-105"
                />
                <span
                  v-if="salon.rating"
                  data-test="rating"
                  class="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full bg-ink/90 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur-sm"
                >
                  ★ {{ salon.rating.average }}
                  <span class="font-normal text-white/70">({{ salon.rating.count }})</span>
                </span>
              </div>

              <div class="p-5">
                <h2 class="font-display text-lg font-semibold text-ink">{{ salon.name }}</h2>
                <p class="mt-1 text-sm text-ink/55">
                  <span v-if="salon.city">{{ salon.city }}</span>
                  <span v-if="salon.city && priceLabel(salon)" class="text-ink/25"> · </span>
                  <span v-if="priceLabel(salon)">{{ priceLabel(salon) }}</span>
                </p>

                <ul v-if="salon.services.length" class="mt-4 flex flex-wrap gap-1.5">
                  <li
                    v-for="svc in salon.services"
                    :key="svc"
                    class="rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700"
                  >
                    {{ svc }}
                  </li>
                </ul>

                <p class="mt-4 text-sm font-semibold text-brand-700 transition-colors group-hover:text-brand-600">
                  View profile →
                </p>
              </div>
            </RouterLink>
          </div>

          <div v-if="salons.length < total" class="mt-8 flex justify-center">
            <button
              type="button"
              data-test="show-more"
              :disabled="loadingMore"
              class="inline-flex items-center justify-center rounded-full border border-brand-200 bg-white/60 px-7 py-3 text-sm font-semibold text-brand-700 transition-all duration-200 hover:border-brand-300 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 disabled:cursor-not-allowed disabled:opacity-60"
              @click="showMore"
            >
              {{ loadingMore ? 'Loading…' : 'Show more' }}
            </button>
          </div>
        </template>

        <!-- Searched/filtered, nothing matched -->
        <div v-else-if="searched" class="mt-12">
          <p class="text-ink/60">
            <span v-if="q.trim()">Nothing matches "{{ q.trim() }}".</span>
            <span v-else>Nothing matches this filter.</span>
          </p>
          <button
            type="button"
            data-test="clear"
            class="mt-4 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
            @click="clear"
          >
            Show all results
          </button>
        </div>

        <!-- Nothing listed at all -->
        <div v-else class="mt-12">
          <p class="text-ink/60">
            Glowhub is just getting started here, so there are no listings to show yet.
          </p>
          <RouterLink
            to="/register"
            class="mt-4 inline-block rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
          >
            Register your business
          </RouterLink>
        </div>
      </div>
    </main>

    <MarketingFooter />
  </div>
</template>
