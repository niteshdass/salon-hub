<script setup>
import { onMounted, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const images = ref([])
const loading = ref(true)
const loadError = ref('')

const uploading = ref(false)
const uploadError = ref('')
const fileInput = ref(null)
const newTitle = ref('')

// The row currently being retitled, and the draft text for it.
const editingId = ref(null)
const editingTitle = ref('')

const pendingDelete = ref(null)
const deleting = ref(false)

// Ids with a request in flight, so their tile can grey out on its own.
const busy = ref(new Set())

function markBusy(id, on) {
  const next = new Set(busy.value)
  on ? next.add(id) : next.delete(id)
  busy.value = next
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/gallery')
    images.value = data.data || []
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load the gallery.').message
  } finally {
    loading.value = false
  }
}

async function upload(event) {
  const file = event.target.files?.[0]
  if (!file) return

  uploading.value = true
  uploadError.value = ''
  try {
    const body = new FormData()
    body.append('image', file)
    if (newTitle.value.trim()) body.append('title', newTitle.value.trim())

    const { data } = await api.post('/gallery', body)
    images.value.push(data.data)
    newTitle.value = ''
  } catch (err) {
    uploadError.value = parseApiError(err, 'Could not upload that image.').message
  } finally {
    uploading.value = false
    // Reset the input so picking the same file again still fires a change.
    if (fileInput.value) fileInput.value.value = ''
  }
}

function startEditing(image) {
  editingId.value = image.id
  editingTitle.value = image.title || ''
}

async function saveTitle(image) {
  const title = editingTitle.value.trim()
  editingId.value = null
  if (title === (image.title || '')) return

  markBusy(image.id, true)
  try {
    const { data } = await api.put(`/gallery/${image.id}`, { title })
    Object.assign(image, data.data)
  } catch (err) {
    uploadError.value = parseApiError(err, 'Could not rename that image.').message
  } finally {
    markBusy(image.id, false)
  }
}

/**
 * Swap a tile with its neighbour. The list is already in sort_order, so
 * trading the two values is the whole move — and the local list is
 * reordered first, because the grid should not lurch after the round trip.
 */
async function move(index, delta) {
  const target = index + delta
  if (target < 0 || target >= images.value.length) return

  const a = images.value[index]
  const b = images.value[target]
  const orderA = a.sort_order
  const orderB = b.sort_order

  images.value[index] = b
  images.value[target] = a
  a.sort_order = orderB
  b.sort_order = orderA

  markBusy(a.id, true)
  markBusy(b.id, true)
  try {
    await Promise.all([
      api.put(`/gallery/${a.id}`, { sort_order: a.sort_order }),
      api.put(`/gallery/${b.id}`, { sort_order: b.sort_order }),
    ])
  } catch (err) {
    uploadError.value = parseApiError(err, 'Could not reorder the gallery.').message
    await load()
  } finally {
    markBusy(a.id, false)
    markBusy(b.id, false)
  }
}

async function confirmDelete() {
  const image = pendingDelete.value
  if (!image) return

  deleting.value = true
  try {
    await api.delete(`/gallery/${image.id}`)
    images.value = images.value.filter((i) => i.id !== image.id)
    pendingDelete.value = null
  } catch (err) {
    uploadError.value = parseApiError(err, 'Could not delete that image.').message
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Gallery</h1>
        <p class="mt-1 text-sm text-slate-500">
          Photos of your work, shown on your public page in this order.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <input
          v-model="newTitle"
          type="text"
          placeholder="Caption (optional)"
          class="w-48 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
        />
        <label
          class="cursor-pointer rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
          :class="uploading && 'pointer-events-none opacity-60'"
        >
          {{ uploading ? 'Uploading…' : 'Add photo' }}
          <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="upload" />
        </label>
      </div>
    </div>

    <p v-if="loadError || uploadError" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ loadError || uploadError }}
    </p>

    <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      <div v-for="n in 4" :key="n" class="h-48 animate-pulse rounded-2xl bg-slate-100" />
    </div>

    <div
      v-else-if="!images.length"
      class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center"
    >
      <p class="text-sm font-medium text-slate-700">No photos yet</p>
      <p class="mt-1 text-sm text-slate-500">
        Add a few shots of your work — they are the first thing a visitor sees.
      </p>
    </div>

    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      <figure
        v-for="(image, index) in images"
        :key="image.id"
        class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition"
        :class="busy.has(image.id) && 'opacity-60'"
      >
        <div class="relative aspect-4/3 bg-slate-100">
          <img :src="image.image_url" :alt="image.title || 'Gallery photo'" class="h-full w-full object-cover" />

          <div class="absolute inset-x-0 top-0 flex justify-between p-2">
            <div class="flex gap-1">
              <button
                type="button"
                :disabled="index === 0"
                class="rounded-lg bg-white/90 px-2 py-1 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-white disabled:opacity-40"
                aria-label="Move earlier"
                @click="move(index, -1)"
              >
                ←
              </button>
              <button
                type="button"
                :disabled="index === images.length - 1"
                class="rounded-lg bg-white/90 px-2 py-1 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-white disabled:opacity-40"
                aria-label="Move later"
                @click="move(index, 1)"
              >
                →
              </button>
            </div>
            <button
              type="button"
              class="rounded-lg bg-white/90 px-2 py-1 text-xs font-semibold text-rose-600 shadow-sm transition hover:bg-white"
              @click="pendingDelete = image"
            >
              Delete
            </button>
          </div>
        </div>

        <figcaption class="px-3 py-2">
          <input
            v-if="editingId === image.id"
            v-model="editingTitle"
            type="text"
            class="w-full rounded-lg border border-slate-300 px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none"
            autofocus
            @blur="saveTitle(image)"
            @keyup.enter="saveTitle(image)"
            @keyup.esc="editingId = null"
          />
          <button
            v-else
            type="button"
            class="w-full truncate text-left text-sm text-slate-600 hover:text-indigo-600"
            @click="startEditing(image)"
          >
            {{ image.title || 'Add a caption' }}
          </button>
        </figcaption>
      </figure>
    </div>

    <ConfirmDialog
      v-if="pendingDelete"
      title="Delete photo"
      message="This removes the photo from your public page and deletes the file."
      :loading="deleting"
      @cancel="pendingDelete = null"
      @confirm="confirmDelete"
    />
  </div>
</template>
