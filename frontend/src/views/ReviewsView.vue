<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PageHeader from '@/components/PageHeader.vue'

// Staff may read reviews but not moderate them — the API is the real gate.
const authStore = useAuthStore()
const canModerate = computed(() => authStore.canManageOperations)

const reviews = ref([])
const meta = ref({ count: 0, average: null })
const loading = ref(false)
const listError = ref('')

// Which reviews are shown: all, only live, or only hidden.
const filter = ref('all')

const busyId = ref(null)
const confirmTarget = ref(null)
const deleting = ref(false)

const filtered = computed(() => {
  if (filter.value === 'published') {
    return reviews.value.filter((r) => r.status === 'published')
  }
  if (filter.value === 'hidden') {
    return reviews.value.filter((r) => r.status === 'hidden')
  }
  return reviews.value
})

// The header used to carry a separate star summary card; the same two numbers
// now read as a sentence under the title.
const subtitle = computed(() => {
  const count = `${meta.value.count} review${meta.value.count === 1 ? '' : 's'}`
  return meta.value.average !== null ? `${meta.value.average} average from ${count}` : count
})

async function loadReviews() {
  loading.value = true
  listError.value = ''
  try {
    const { data } = await api.get('/reviews')
    reviews.value = data.data || []
    meta.value = data.meta || { count: 0, average: null }
  } catch (err) {
    listError.value = parseApiError(err, 'Could not load reviews.').message
  } finally {
    loading.value = false
  }
}

async function setStatus(review, status) {
  busyId.value = review.id
  listError.value = ''
  try {
    const { data } = await api.patch(`/reviews/${review.id}`, { status })
    Object.assign(review, data.data)
  } catch (err) {
    listError.value = parseApiError(err, 'Could not update review.').message
  } finally {
    busyId.value = null
  }
}

async function confirmDelete() {
  if (!confirmTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/reviews/${confirmTarget.value.id}`)
    confirmTarget.value = null
    await loadReviews()
  } catch (err) {
    listError.value = parseApiError(err, 'Could not delete review.').message
    confirmTarget.value = null
  } finally {
    deleting.value = false
  }
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

onMounted(loadReviews)
</script>

<template>
  <div>
    <PageHeader title="Reviews" :subtitle="subtitle" />

    <div
      v-if="listError"
      class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
    >
      {{ listError }}
    </div>

    <!-- Filter tabs -->
    <div class="mb-5 inline-flex rounded-lg bg-ink/5 p-1 text-sm">
      <button
        v-for="tab in ['all', 'published', 'hidden']"
        :key="tab"
        type="button"
        class="rounded-md px-3 py-1.5 font-medium capitalize transition"
        :class="filter === tab ? 'bg-white text-ink shadow-sm' : 'text-ink/60 hover:text-ink'"
        @click="filter = tab"
      >
        {{ tab }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="sh-card p-10 text-center text-sm text-ink/60">Loading reviews…</div>

    <!-- Empty -->
    <div v-else-if="filtered.length === 0" class="sh-empty">
      <p class="font-medium text-ink">
        {{ reviews.length === 0 ? 'No reviews yet' : 'Nothing here' }}
      </p>
      <p class="mt-1">
        {{ reviews.length === 0 ? 'Reviews appear once customers rate a completed booking.' : 'No reviews match this filter.' }}
      </p>
    </div>

    <!-- List -->
    <div v-else class="space-y-3">
      <div
        v-for="review in filtered"
        :key="review.id"
        class="sh-card p-5"
        :class="review.status === 'hidden' ? 'opacity-60' : ''"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <div class="flex text-accent-500">
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
              <span v-if="review.status === 'hidden'" class="sh-badge bg-ink/10 text-ink/60">Hidden</span>
            </div>
            <p class="mt-2 font-medium text-ink">{{ review.reviewer_name }}</p>
            <p class="text-xs text-ink/55">
              {{ review.service_name || 'Service' }}
              <template v-if="review.staff_name"> · with {{ review.staff_name }}</template>
              · {{ formatDate(review.booking_date) }}
            </p>
          </div>

          <div v-if="canModerate" class="flex gap-2">
            <button
              type="button"
              :disabled="busyId === review.id"
              class="sh-btn px-2.5 py-1 text-xs"
              @click="setStatus(review, review.status === 'hidden' ? 'published' : 'hidden')"
            >
              {{ review.status === 'hidden' ? 'Unhide' : 'Hide' }}
            </button>
            <button
              type="button"
              class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
              @click="confirmTarget = review"
            >
              Delete
            </button>
          </div>
        </div>

        <p v-if="review.comment" class="mt-3 text-sm text-ink/70">{{ review.comment }}</p>
      </div>
    </div>

    <!-- Delete confirm -->
    <ConfirmDialog
      v-if="confirmTarget"
      title="Delete review"
      message="Delete this review permanently? This cannot be undone."
      :loading="deleting"
      @confirm="confirmDelete"
      @cancel="confirmTarget = null"
    />
  </div>
</template>
