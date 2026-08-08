<script setup>
/*
 * The title block every admin page opens with. Views render it themselves
 * rather than the layout reading route meta — the subtitle is usually derived
 * from loaded data ("1 appointment in this view"), which a static route
 * label cannot express.
 */
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})
</script>

<template>
  <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
      <h1 class="font-display text-4xl leading-tight text-ink sm:text-5xl">{{ title }}</h1>
      <p v-if="subtitle" class="mt-2 text-sm text-ink/60">{{ subtitle }}</p>
    </div>

    <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
