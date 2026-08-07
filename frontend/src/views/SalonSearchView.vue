<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'
import { searchSalons } from '@/lib/discovery'

const route = useRoute()
const router = useRouter()

// The URL is the source of truth for what was searched, so a result page can
// be shared, reloaded and reached again with the back button.
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const salons = ref([])
const total = ref(0)
const page = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const failed = ref(false)

// Set once a search has actually been run with text in the box, so the empty
// state can tell "nothing matched your words" from "nothing is listed yet".
const searched = ref(q.value.trim() !== '')

let timer = null
// Only the newest search may write results: a slow request for "ha" must not
// overwrite the finished one for "hair".
let latest = 0

async function run() {
  const term = q.value.trim()
  const attempt = ++latest

  loading.value = true
  failed.value = false
  searched.value = term !== ''
  page.value = 1
  // A fresh search always wins the race (see the `attempt !== latest` guards
  // below and in showMore()), so any "Show more" in flight is now stale and
  // its own `finally` will never run to clear this. Reset it unconditionally
  // here or the button is stuck reading "Loading…", disabled, forever.
  loadingMore.value = false

  try {
    const { data, meta } = await searchSalons({ q: term, page: 1 })
    if (attempt !== latest) return
    salons.value = data
    total.value = meta.total
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
    const { data } = await searchSalons({ q: term, page: nextPage })
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

function onInput() {
  clearTimeout(timer)
  timer = setTimeout(() => {
    const term = q.value.trim()
    router.replace({ query: term ? { q: term } : {} })
    run()
  }, 300)
}

function clear() {
  q.value = ''
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
      <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">Find a salon</h1>
      <p class="mt-3 max-w-xl text-ink/60">
        Search by salon, city, or the thing you want done.
      </p>

      <div class="relative mt-8 max-w-xl">
        <input
          v-model="q"
          type="search"
          placeholder="Hair spa, Sylhet, Chastity Hyde…"
          aria-label="Search salons"
          class="w-full rounded-full border border-brand-100 bg-white px-6 py-3.5 text-base text-ink shadow-sm transition-shadow placeholder:text-ink/35 focus-visible:border-brand-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/30"
          @input="onInput"
        />
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
          Couldn't load salons. Check your connection and try again.
        </p>

        <!-- Results -->
        <template v-else-if="salons.length">
          <p class="mt-8 text-sm text-ink/50">{{ total }} {{ total === 1 ? 'salon' : 'salons' }}</p>

          <div class="mt-5 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <RouterLink
              v-for="salon in salons"
              :key="salon.slug"
              :to="`/salon/${salon.slug}`"
              class="group block overflow-hidden rounded-2xl border border-brand-100 bg-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
            >
              <div class="h-40 bg-brand-50">
                <img
                  v-if="salon.cover_image_url"
                  :src="salon.cover_image_url"
                  :alt="salon.name"
                  class="h-40 w-full object-cover transition-transform duration-500 group-hover:scale-105"
                />
              </div>

              <div class="p-5">
                <h2 class="font-display text-lg font-semibold text-ink">{{ salon.name }}</h2>
                <p class="mt-1 text-sm text-ink/55">
                  <span v-if="salon.city">{{ salon.city }}</span>
                  <span v-if="salon.city && priceLabel(salon)" class="text-ink/25"> · </span>
                  <span v-if="priceLabel(salon)">{{ priceLabel(salon) }}</span>
                </p>

                <p v-if="salon.rating" data-test="rating" class="mt-2 text-sm text-ink/70">
                  ★ {{ salon.rating.average }}
                  <span class="text-ink/40">({{ salon.rating.count }})</span>
                </p>

                <ul v-if="salon.services.length" class="mt-4 flex flex-wrap gap-1.5">
                  <li
                    v-for="service in salon.services"
                    :key="service"
                    class="rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700"
                  >
                    {{ service }}
                  </li>
                </ul>
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

        <!-- Searched, nothing matched -->
        <div v-else-if="searched" class="mt-12">
          <p class="text-ink/60">Nothing matches "{{ q.trim() }}".</p>
          <button
            type="button"
            data-test="clear"
            class="mt-4 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
            @click="clear"
          >
            Show all salons
          </button>
        </div>

        <!-- Nothing listed at all -->
        <div v-else class="mt-12">
          <p class="text-ink/60">
            SalonHub is just getting started here, so there are no salons to show yet.
          </p>
          <RouterLink
            to="/register"
            class="mt-4 inline-block rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
          >
            Register a salon
          </RouterLink>
        </div>
      </div>
    </main>

    <MarketingFooter />
  </div>
</template>
