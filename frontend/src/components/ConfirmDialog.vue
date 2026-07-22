<script setup>
import Modal from './Modal.vue'

defineProps({
  title: { type: String, default: 'Delete item' },
  message: { type: String, default: 'Are you sure? This cannot be undone.' },
  confirmText: { type: String, default: 'Delete' },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'cancel'])
</script>

<template>
  <Modal :title="title" size="sm" @close="emit('cancel')">
    <p class="text-sm text-slate-600">{{ message }}</p>

    <template #footer>
      <button
        type="button"
        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
        @click="emit('cancel')"
      >
        Cancel
      </button>
      <button
        type="button"
        :disabled="loading"
        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
        @click="emit('confirm')"
      >
        {{ loading ? 'Deleting…' : confirmText }}
      </button>
    </template>
  </Modal>
</template>
