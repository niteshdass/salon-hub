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
    <p class="text-sm text-ink/60">{{ message }}</p>

    <template #footer>
      <button type="button" class="sh-btn" @click="emit('cancel')">Cancel</button>
      <button
        type="button"
        :disabled="loading"
        class="sh-btn sh-btn-danger"
        @click="emit('confirm')"
      >
        {{ loading ? 'Deleting…' : confirmText }}
      </button>
    </template>
  </Modal>
</template>
